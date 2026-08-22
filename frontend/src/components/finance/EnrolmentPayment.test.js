import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import EnrolmentPayment from './EnrolmentPayment.vue'

const apiMock = vi.fn()

vi.mock('../../api.js', () => ({
  useApi: () => ({ api: apiMock, apiBaseUrl: '', apiBlob: vi.fn(), authenticated: { value: true } }),
}))

const notifyError = vi.fn()
const notifySuccess = vi.fn()

vi.mock('../../notify.js', () => ({
  notifyError: (...args) => notifyError(...args),
  notifySuccess: (...args) => notifySuccess(...args),
}))

/**
 * The screen that takes money at the counter. What it sends has to match what
 * the cashier ticked, to the cent.
 */
describe('EnrolmentPayment', () => {
  const fees = [
    { id: 1, name: 'قسط سنوي', category: 'رسوم دراسية', due_date: '2026-09-30', outstanding: 3000 },
    { id: 2, name: 'كتب', category: 'كتب', due_date: '2026-10-15', outstanding: 450 },
    { id: 3, name: 'زي مدرسي', category: 'زي', due_date: null, outstanding: 0 },
  ]

  beforeEach(() => {
    apiMock.mockReset()
    notifyError.mockReset()
    notifySuccess.mockReset()

    apiMock.mockImplementation((path) => {
      if (path.includes('assign-fees')) return Promise.resolve({ data: {} })
      if (path.includes('/account')) {
        return Promise.resolve({ data: { fees, totals: { outstanding: 3450 } } })
      }
      if (path === '/finance/setup') {
        return Promise.resolve({ data: { methods: [{ id: 1, name: 'نقداً' }, { id: 2, name: 'شيك' }] } })
      }
      return Promise.resolve({ data: { payment: { receipt_number: 7 } } })
    })
  })

  async function open() {
    const wrapper = mount(EnrolmentPayment, {
      props: { studentId: 5, studentName: 'BAHAR', academicYear: '2026-2027', canManageFees: true },
    })
    await flushPromises()
    return wrapper
  }

  it('lists only the fees that still owe something', async () => {
    const wrapper = await open()
    const rows = wrapper.findAll('tbody tr')

    expect(rows).toHaveLength(2)
    expect(wrapper.text()).toContain('قسط سنوي')
    expect(wrapper.text()).not.toContain('زي مدرسي')
  })

  it('raises the year\'s fees before showing anything', async () => {
    await open()

    expect(apiMock).toHaveBeenCalledWith(
      '/finance/students/5/assign-fees',
      expect.objectContaining({ method: 'POST' }),
    )
  })

  it('does not try to raise fees without the permission', async () => {
    const wrapper = mount(EnrolmentPayment, {
      props: { studentId: 5, studentName: 'BAHAR', academicYear: '2026-2027', canManageFees: false },
    })
    await flushPromises()

    expect(apiMock).not.toHaveBeenCalledWith(
      expect.stringContaining('assign-fees'),
      expect.anything(),
    )
  })

  it('sends only the ticked fees, at the amounts shown', async () => {
    const wrapper = await open()

    await wrapper.findAll('tbody input[type="checkbox"]')[1].setValue(true)
    await wrapper.find('select').setValue('نقداً')
    apiMock.mockClear()

    await wrapper.findAll('button').find((b) => b.text().includes('تسجيل الدفعة')).trigger('click')
    await flushPromises()

    const [path, options] = apiMock.mock.calls[0]
    const body = JSON.parse(options.body)

    expect(path).toBe('/finance/students/5/payments')
    expect(body.amount).toBe(450)
    expect(body.allocations).toEqual([{ student_fee_id: 2, amount: 450 }])
    expect(body.method).toBe('نقداً')
  })

  it('adds up several ticked fees', async () => {
    const wrapper = await open()

    const boxes = wrapper.findAll('tbody input[type="checkbox"]')
    await boxes[0].setValue(true)
    await boxes[1].setValue(true)
    apiMock.mockClear()

    await wrapper.findAll('button').find((b) => b.text().includes('تسجيل الدفعة')).trigger('click')
    await flushPromises()

    const body = JSON.parse(apiMock.mock.calls[0][1].body)
    expect(body.amount).toBe(3450)
    expect(body.allocations).toHaveLength(2)
  })

  it('refuses to post a payment with nothing selected', async () => {
    const wrapper = await open()
    apiMock.mockClear()

    const collect = wrapper.findAll('button').find((b) => b.text().includes('تسجيل الدفعة'))
    expect(collect.attributes('disabled')).toBeDefined()

    await collect.trigger('click')
    await flushPromises()

    expect(apiMock).not.toHaveBeenCalled()
  })

  // Skipping is a first-class outcome: the balance stays owed.
  it('closes without charging anything when skipped', async () => {
    const wrapper = await open()
    apiMock.mockClear()

    await wrapper.findAll('button').find((b) => b.text().includes('تخطي')).trigger('click')
    await flushPromises()

    expect(apiMock).not.toHaveBeenCalled()
    expect(wrapper.emitted('done')).toBeTruthy()
  })

  it('reports the receipt number after taking the money', async () => {
    const wrapper = await open()

    await wrapper.findAll('tbody input[type="checkbox"]')[1].setValue(true)
    await wrapper.findAll('button').find((b) => b.text().includes('تسجيل الدفعة')).trigger('click')
    await flushPromises()

    expect(notifySuccess).toHaveBeenCalledWith(expect.stringContaining('7'))
    expect(wrapper.emitted('done')).toBeTruthy()
  })

  it('says so plainly when the student owes nothing', async () => {
    apiMock.mockImplementation((path) => {
      if (path.includes('assign-fees')) return Promise.resolve({ data: {} })
      if (path.includes('/account')) return Promise.resolve({ data: { fees: [], totals: { outstanding: 0 } } })
      return Promise.resolve({ data: { methods: [] } })
    })

    const wrapper = await open()

    expect(wrapper.text()).toContain('لا توجد رسوم مستحقة')
    expect(wrapper.findAll('button').some((b) => b.text().includes('متابعة'))).toBe(true)
  })
})
