import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import CashAdvances from './CashAdvances.vue'

const apiMock = vi.fn()

vi.mock('../../api.js', () => ({
  useApi: () => ({ api: apiMock, apiBaseUrl: '', apiBlob: vi.fn(), apiUpload: vi.fn(), authenticated: { value: true } }),
}))

const notifyError = vi.fn()
const notifySuccess = vi.fn()

vi.mock('../../notify.js', () => ({
  notifyError: (...args) => notifyError(...args),
  notifySuccess: (...args) => notifySuccess(...args),
  notifyInfo: vi.fn(),
}))

const confirmAction = vi.fn(() => Promise.resolve(true))
const confirmDelete = vi.fn(() => Promise.resolve(true))

vi.mock('../../confirm.js', () => ({
  confirmAction: (...args) => confirmAction(...args),
  confirmDelete: (...args) => confirmDelete(...args),
}))

/**
 * The advances screen. The closing figure is never typed here — the tests check
 * the screen tells the truth about which way the difference goes before anyone
 * signs it off.
 */
describe('CashAdvances', () => {
  function advance(overrides = {}) {
    return {
      id: 3,
      advance_number: 7,
      academic_year: '2026-2027',
      holder_id: null,
      holder_name: 'محمد الأمين',
      purpose: 'مستلزمات مكتبية',
      amount: 1000,
      method: 'نقداً',
      reference: null,
      issued_on: '2026-09-01',
      notes: null,
      status: 'open',
      spent: 850,
      outstanding: 150,
      returned_amount: 0,
      reimbursed_amount: 0,
      issued_by: 'System Admin',
      settled_at: null,
      settled_by: null,
      cancel_reason: null,
      expenses_count: 1,
      expenses: [
        { id: 11, description: 'قرطاسية', amount: 850, spent_on: '2026-09-05', reference: 'INV-9', recorded_by: 'System Admin' },
      ],
      ...overrides,
    }
  }

  let detail = advance()

  const can = (permission) => ['finance.view', 'finance.advances.manage', 'finance.advances.settle'].includes(permission)

  beforeEach(() => {
    apiMock.mockReset()
    notifyError.mockReset()
    notifySuccess.mockReset()
    confirmAction.mockClear()
    confirmDelete.mockClear()
    confirmAction.mockResolvedValue(true)
    confirmDelete.mockResolvedValue(true)

    detail = advance()

    apiMock.mockImplementation((path, options = {}) => {
      if (path.startsWith('/finance/advances?')) {
        return Promise.resolve({
          data: [advance()],
          totals: { open_count: 1, open_amount: 1000, open_outstanding: 150 },
          methods: ['نقداً', 'شيك'],
          staff: [{ id: 5, name: 'Sara', user_type: 'staff' }],
        })
      }
      if (path === '/finance/advances' && options.method === 'POST') {
        return Promise.resolve({ data: detail })
      }
      if (path.endsWith('/settle')) {
        return Promise.resolve({ data: advance({ status: 'settled', outstanding: 0, returned_amount: 150 }), message: 'أُقفلت العهدة، والمبلغ المرتجع 150.' })
      }
      if (path.match(/\/finance\/advances\/\d+$/)) return Promise.resolve({ data: detail })

      return Promise.resolve({ data: detail })
    })
  })

  async function open(perms = can) {
    const wrapper = mount(CashAdvances, { props: { can: perms } })
    await flushPromises()

    return wrapper
  }

  async function openDetail(wrapper) {
    await wrapper.findAll('button').find((b) => b.text() === 'تفاصيل').trigger('click')
    await flushPromises()
  }

  it('lists advances with what is still unaccounted for', async () => {
    const wrapper = await open()

    expect(wrapper.text()).toContain('محمد الأمين')
    expect(wrapper.text()).toContain('عهد مفتوحة')
    // 1000 issued, 850 spent, 150 still out.
    expect(wrapper.text()).toContain('150.00')
  })

  it('issues an advance with the amount as a number', async () => {
    const wrapper = await open()
    const inputs = wrapper.findAll('input')

    await wrapper.findAll('label').find((l) => l.text().includes('اسم المستلم')).find('input').setValue('علي')
    await wrapper.findAll('label').find((l) => l.text().includes('الغرض')).find('input').setValue('صيانة')
    await wrapper.findAll('label').find((l) => l.text().includes('المبلغ')).find('input').setValue('750')
    expect(inputs.length).toBeGreaterThan(0)
    apiMock.mockClear()

    await wrapper.findAll('button').find((b) => b.text() === 'صرف العهدة').trigger('click')
    await flushPromises()

    const [path, options] = apiMock.mock.calls[0]
    const body = JSON.parse(options.body)

    expect(path).toBe('/finance/advances')
    expect(body.amount).toBe(750)
    expect(body.holder_name).toBe('علي')
    expect(body.holder_id).toBeNull()
  })

  it('refuses to issue an advance with nothing filled in', async () => {
    const wrapper = await open()
    apiMock.mockClear()

    await wrapper.findAll('button').find((b) => b.text() === 'صرف العهدة').trigger('click')
    await flushPromises()

    expect(notifyError).toHaveBeenCalled()
    expect(apiMock).not.toHaveBeenCalled()
  })

  it('shows what was spent against the advance', async () => {
    const wrapper = await open()
    await openDetail(wrapper)

    expect(wrapper.find('.student-modal').text()).toContain('قرطاسية')
    expect(wrapper.find('.student-modal').text()).toContain('INV-9')
  })

  it('says the difference is coming back before it is signed off', async () => {
    const wrapper = await open()
    await openDetail(wrapper)

    await wrapper.findAll('.student-modal button').find((b) => b.text() === 'إقفال العهدة').trigger('click')
    await flushPromises()

    const [, options] = confirmAction.mock.calls[0]
    expect(options.detail).toContain('150.00')
    expect(options.detail).toContain('يُسترد')
  })

  it('warns that the school owes the holder when the advance was overspent', async () => {
    detail = advance({ spent: 1120, outstanding: -120 })
    const wrapper = await open()
    await openDetail(wrapper)

    await wrapper.findAll('.student-modal button').find((b) => b.text() === 'إقفال العهدة').trigger('click')
    await flushPromises()

    const [, options] = confirmAction.mock.calls[0]
    expect(options.detail).toContain('120.00')
    expect(options.detail).toContain('مستحقاً للمستلم')
  })

  it('sends no amount when closing — the server works it out', async () => {
    const wrapper = await open()
    await openDetail(wrapper)
    apiMock.mockClear()

    await wrapper.findAll('.student-modal button').find((b) => b.text() === 'إقفال العهدة').trigger('click')
    await flushPromises()

    const [path, options] = apiMock.mock.calls[0]
    expect(path).toBe('/finance/advances/3/settle')
    expect(options.method).toBe('POST')
    expect(options.body).toBeUndefined()
  })

  it('stops if the closing is not confirmed', async () => {
    confirmAction.mockResolvedValueOnce(false)
    const wrapper = await open()
    await openDetail(wrapper)
    apiMock.mockClear()

    await wrapper.findAll('.student-modal button').find((b) => b.text() === 'إقفال العهدة').trigger('click')
    await flushPromises()

    expect(apiMock).not.toHaveBeenCalled()
  })

  it('offers no cancel once something has been spent', async () => {
    const wrapper = await open()
    await openDetail(wrapper)

    expect(wrapper.findAll('.student-modal button').some((b) => b.text() === 'إلغاء العهدة')).toBe(false)
  })

  it('offers reopen instead of closing once the advance is settled', async () => {
    detail = advance({ status: 'settled', outstanding: 0, returned_amount: 150, settled_at: '2026-09-30 10:00:00', settled_by: 'System Admin' })
    const wrapper = await open()
    await openDetail(wrapper)

    const buttons = wrapper.findAll('.student-modal button').map((b) => b.text())
    expect(buttons).toContain('إعادة فتح')
    expect(buttons).not.toContain('إقفال العهدة')
    // The spending form is gone too.
    expect(wrapper.findAll('.student-modal button').some((b) => b.text() === 'إضافة بند')).toBe(false)
  })

  it('hides the closing button from someone who may only record spending', async () => {
    const wrapper = await open((permission) => ['finance.view', 'finance.advances.manage'].includes(permission))
    await openDetail(wrapper)

    const buttons = wrapper.findAll('.student-modal button').map((b) => b.text())
    expect(buttons).toContain('إضافة بند')
    expect(buttons).not.toContain('إقفال العهدة')
  })

  it('hides the issuing form from someone who may only sign off', async () => {
    const wrapper = await open((permission) => ['finance.view', 'finance.advances.settle'].includes(permission))

    expect(wrapper.findAll('button').some((b) => b.text() === 'صرف العهدة')).toBe(false)
  })
})
