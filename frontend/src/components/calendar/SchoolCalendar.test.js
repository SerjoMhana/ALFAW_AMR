import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import SchoolCalendar from './SchoolCalendar.vue'

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

const confirmDelete = vi.fn(() => Promise.resolve(true))

vi.mock('../../confirm.js', () => ({
  confirmDelete: (...args) => confirmDelete(...args),
  confirmAction: vi.fn(() => Promise.resolve(true)),
}))

/**
 * One calendar, two audiences: the office gets the buttons, everyone else gets
 * the month. The tests are mostly about that split.
 */
describe('SchoolCalendar', () => {
  const kindColors = {
    activity: '#465fff',
    holiday: '#12b76a',
    exam: '#f04438',
    meeting: '#f79009',
    other: '#667085',
  }

  // The component opens on the current month, so the fixture lives there too —
  // otherwise the test would start failing on the first of a month.
  const now = new Date()
  const on = (day) => `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`

  let events = []
  let canManage = true

  beforeEach(() => {
    apiMock.mockReset()
    notifyError.mockReset()
    notifySuccess.mockReset()
    confirmDelete.mockClear()
    confirmDelete.mockResolvedValue(true)

    canManage = true
    events = [{
      id: 1,
      title: 'رحلة مدرسية',
      description: null,
      kind: 'activity',
      color: '#ff8800',
      starts_on: on(10),
      ends_on: on(12),
      all_day: true,
      starts_at: null,
      ends_at: null,
      location: 'طرابلس',
      days: 3,
    }]

    apiMock.mockImplementation((path) => {
      if (path.startsWith('/calendar-events?')) {
        return Promise.resolve({
          data: events,
          kinds: Object.keys(kindColors),
          kind_colors: kindColors,
          can_manage: canManage,
        })
      }

      return Promise.resolve({ data: {} })
    })
  })

  async function open() {
    const wrapper = mount(SchoolCalendar)
    await flushPromises()

    return wrapper
  }

  it('paints the event on every day it spans', async () => {
    const wrapper = await open()

    // The trip runs 10–12 September, so it shows on three cells.
    const painted = wrapper.findAll('.calendar-event').filter((el) => el.text() === 'رحلة مدرسية')
    expect(painted).toHaveLength(3)
    expect(painted[0].attributes('style')).toContain('rgb(255, 136, 0)')
  })

  it('lists the month below the grid', async () => {
    const wrapper = await open()

    expect(wrapper.text()).toContain('رحلة مدرسية')
    expect(wrapper.text()).toContain('طرابلس')
    expect(wrapper.text()).toContain(`${on(10)} → ${on(12)}`)
  })

  it('asks for the month it is showing when the arrows are used', async () => {
    const wrapper = await open()
    apiMock.mockClear()

    await wrapper.findAll('button').find((b) => b.text() === '›').trigger('click')
    await flushPromises()

    const [path] = apiMock.mock.calls[0]
    const asked = new URLSearchParams(path.split('?')[1])
    const now = new Date()
    const expected = new Date(now.getFullYear(), now.getMonth() + 1, 1)

    expect(Number(asked.get('month'))).toBe(expected.getMonth() + 1)
    expect(Number(asked.get('year'))).toBe(expected.getFullYear())
  })

  it('sends a new event with the colour of its kind', async () => {
    const wrapper = await open()

    await wrapper.findAll('button').find((b) => b.text().includes('إضافة فعالية')).trigger('click')
    await wrapper.find('.student-modal input').setValue('يوم رياضي')
    await wrapper.find('.student-modal select').setValue('holiday')
    apiMock.mockClear()

    await wrapper.findAll('.student-modal button').find((b) => b.text() === 'حفظ').trigger('click')
    await flushPromises()

    const [path, options] = apiMock.mock.calls[0]
    const body = JSON.parse(options.body)

    expect(path).toBe('/calendar-events')
    expect(options.method).toBe('POST')
    expect(body.title).toBe('يوم رياضي')
    expect(body.kind).toBe('holiday')
    // The colour followed the kind because it had not been picked by hand.
    expect(body.color).toBe(kindColors.holiday)
    // A single day unless an end is given.
    expect(body.ends_on).toBe(body.starts_on)
  })

  it('refuses to save an event with no title', async () => {
    const wrapper = await open()

    await wrapper.findAll('button').find((b) => b.text().includes('إضافة فعالية')).trigger('click')
    apiMock.mockClear()

    await wrapper.findAll('.student-modal button').find((b) => b.text() === 'حفظ').trigger('click')
    await flushPromises()

    expect(notifyError).toHaveBeenCalled()
    expect(apiMock).not.toHaveBeenCalled()
  })

  it('drops the times from an all-day event', async () => {
    const wrapper = await open()

    await wrapper.findAll('.calendar-event').at(0).trigger('click')
    await flushPromises()
    apiMock.mockClear()

    await wrapper.findAll('.student-modal button').find((b) => b.text() === 'حفظ').trigger('click')
    await flushPromises()

    const body = JSON.parse(apiMock.mock.calls[0][1].body)
    expect(apiMock.mock.calls[0][0]).toBe('/calendar-events/1')
    expect(apiMock.mock.calls[0][1].method).toBe('PUT')
    expect(body.starts_at).toBeNull()
  })

  it('asks before removing an event', async () => {
    confirmDelete.mockResolvedValueOnce(false)
    const wrapper = await open()
    apiMock.mockClear()

    await wrapper.findAll('.event-row button').find((b) => b.text() === 'حذف').trigger('click')
    await flushPromises()

    expect(confirmDelete).toHaveBeenCalled()
    expect(apiMock).not.toHaveBeenCalled()
  })

  it('gives a reader the month but none of the buttons', async () => {
    canManage = false
    const wrapper = await open()

    expect(wrapper.text()).toContain('رحلة مدرسية')
    expect(wrapper.findAll('button').some((b) => b.text().includes('إضافة فعالية'))).toBe(false)
    expect(wrapper.findAll('.event-row button')).toHaveLength(0)

    // Clicking a day opens nothing for someone who cannot write.
    await wrapper.findAll('.calendar-cell').at(10).trigger('click')
    expect(wrapper.find('.student-modal').exists()).toBe(false)
  })

  it('says plainly when the month is empty', async () => {
    events = []
    const wrapper = await open()

    expect(wrapper.text()).toContain('لا توجد فعاليات هذا الشهر.')
  })
})
