import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import AttendanceRegister from './AttendanceRegister.vue'

const apiMock = vi.fn()
const apiBlobMock = vi.fn()

vi.mock('../../api.js', () => ({
  useApi: () => ({ api: apiMock, apiBlob: apiBlobMock, apiUpload: vi.fn(), apiBaseUrl: '', authenticated: { value: true } }),
}))

const notifyError = vi.fn()
const notifySuccess = vi.fn()

vi.mock('../../notify.js', () => ({
  notifyError: (...args) => notifyError(...args),
  notifySuccess: (...args) => notifySuccess(...args),
  notifyInfo: vi.fn(),
}))

/**
 * The register. What matters is what reaches the server: only the students the
 * office actually marked, and never a silent "everyone was here".
 */
describe('AttendanceRegister', () => {
  const sections = [{ id: 4, class_name: 'G12', section_code: 'G12-A', academic_year: '2026-2027' }]

  const day = {
    students: [
      { student_profile: { id: 1, full_name: 'Ali', admission_no: 'S-1' }, record: null },
      { student_profile: { id: 2, full_name: 'Sara', admission_no: 'S-2' }, record: { status: 'absent', notes: 'مريضة' } },
    ],
  }

  const month = {
    section: { id: 4, name: 'G12', section_code: 'G12-A', academic_year: '2026-2027' },
    month_label: 'September 2026',
    days: [
      { day: 1, date: '2026-09-01', weekday: 2, is_weekend: false },
      { day: 2, date: '2026-09-02', weekday: 3, is_weekend: false },
      { day: 4, date: '2026-09-04', weekday: 5, is_weekend: true },
    ],
    students: [{
      no: 1,
      student_profile_id: 1,
      name: 'Ali',
      admission_no: 'S-1',
      marks: { '2026-09-01': { status: 'absent', mark: 'غ', notes: null }, '2026-09-02': null, '2026-09-04': null },
      totals: { present: 0, absent: 1, late: 0, excused: 0 },
      recorded_days: 1,
    }],
    legend: { present: '✓', absent: 'غ', late: 'ت', excused: 'م' },
    recorded_dates: ['2026-09-01'],
  }

  beforeEach(() => {
    apiMock.mockReset()
    apiBlobMock.mockReset()
    notifyError.mockReset()
    notifySuccess.mockReset()

    apiMock.mockImplementation((path) => {
      if (path === '/course-sections') return Promise.resolve({ data: sections })
      if (path.includes('/attendance/month')) return Promise.resolve({ data: month })
      if (path.includes('/attendance?date=')) return Promise.resolve(day)

      return Promise.resolve({ data: {} })
    })
  })

  async function open() {
    const wrapper = mount(AttendanceRegister)
    await flushPromises()

    return wrapper
  }

  it('opens on the first class and shows who is already marked', async () => {
    const wrapper = await open()

    expect(wrapper.text()).toContain('Ali')
    expect(wrapper.text()).toContain('Sara')
    // Sara was already down as absent; Ali is untouched.
    expect(wrapper.findAll('.status-chip.on')).toHaveLength(1)
    expect(wrapper.find('.status-chip.absent.on').exists()).toBe(true)
  })

  it('counts the unmarked rather than assuming they were present', async () => {
    const wrapper = await open()

    expect(wrapper.text()).toContain('بلا تحديد')
    // One of the two has no mark at all.
    expect(wrapper.text()).toMatch(/بلا تحديد[^0-9]*1/)
  })

  it('sends only the students who were actually marked', async () => {
    const wrapper = await open()
    apiMock.mockClear()

    await wrapper.findAll('button').find((b) => b.text() === 'حفظ حضور اليوم').trigger('click')
    await flushPromises()

    const [path, options] = apiMock.mock.calls[0]
    const body = JSON.parse(options.body)

    expect(path).toBe('/course-sections/4/attendance')
    expect(options.method).toBe('PUT')
    expect(body.records).toHaveLength(1)
    expect(body.records[0]).toMatchObject({ student_profile_id: 2, status: 'absent', notes: 'مريضة' })
  })

  it('marks the whole class in one press', async () => {
    const wrapper = await open()

    await wrapper.findAll('button').find((b) => b.text() === 'الجميع حاضر').trigger('click')
    apiMock.mockClear()

    await wrapper.findAll('button').find((b) => b.text() === 'حفظ حضور اليوم').trigger('click')
    await flushPromises()

    const body = JSON.parse(apiMock.mock.calls[0][1].body)
    expect(body.records).toHaveLength(2)
    expect(body.records.every((row) => row.status === 'present')).toBe(true)
  })

  it('refuses to save a day where nobody was marked', async () => {
    const wrapper = await open()

    await wrapper.findAll('button').find((b) => b.text() === 'مسح التحديد').trigger('click')
    apiMock.mockClear()

    await wrapper.findAll('button').find((b) => b.text() === 'حفظ حضور اليوم').trigger('click')
    await flushPromises()

    expect(notifyError).toHaveBeenCalled()
    expect(apiMock).not.toHaveBeenCalled()
  })

  it('shows the month grid with the marks in place', async () => {
    const wrapper = await open()

    await wrapper.findAll('button').find((b) => b.text() === 'كشف الشهر').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('September 2026')
    expect(wrapper.find('.day-cell.absent').text()).toBe('غ')
    expect(wrapper.findAll('.month-grid .weekend').length).toBeGreaterThan(0)
  })

  it('asks for the blank sheet and the filled one separately', async () => {
    apiBlobMock.mockResolvedValue(new Blob(['%PDF']))
    global.URL.createObjectURL = vi.fn(() => 'blob:x')
    global.URL.revokeObjectURL = vi.fn()

    const wrapper = await open()
    await wrapper.findAll('button').find((b) => b.text() === 'كشف الشهر').trigger('click')
    await flushPromises()

    await wrapper.findAll('button').find((b) => b.text().includes('كشف فارغ')).trigger('click')
    await flushPromises()
    expect(apiBlobMock.mock.calls[0][0]).not.toContain('filled=1')

    await wrapper.findAll('button').find((b) => b.text().includes('معبّأً')).trigger('click')
    await flushPromises()
    expect(apiBlobMock.mock.calls[1][0]).toContain('filled=1')
  })
})
