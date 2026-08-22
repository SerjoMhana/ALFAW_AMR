import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import GoogleClassroom from './GoogleClassroom.vue'

const apiMock = vi.fn()

vi.mock('../../api.js', () => ({
  useApi: () => ({ api: apiMock, apiBaseUrl: '', apiBlob: vi.fn(), authenticated: { value: true } }),
}))

const notifyError = vi.fn()
const notifySuccess = vi.fn()
const notifyInfo = vi.fn()

vi.mock('../../notify.js', () => ({
  notifyError: (...args) => notifyError(...args),
  notifySuccess: (...args) => notifySuccess(...args),
  notifyInfo: (...args) => notifyInfo(...args),
}))

const confirmAction = vi.fn(() => Promise.resolve(true))

vi.mock('../../confirm.js', () => ({
  confirmAction: (...args) => confirmAction(...args),
}))

/**
 * The page that pushes classes into Google. What it sends decides who ends up
 * on a real roster, so the checks are about exactly that.
 */
describe('GoogleClassroom', () => {
  const settings = {
    enabled: false,
    domain: 'vis.edu.ly',
    impersonate: 'classroom@vis.edu.ly',
    credentials_present: false,
  }

  const status = {
    class: { id: 5, name: 'G12', academic_year: '2026-2027' },
    students: [
      { id: 1, user_id: 11, name: 'Ali', admission_no: 'A-1', google_email: 'ali@vis.edu.ly', ready: true },
      { id: 2, user_id: 12, name: 'Omar', admission_no: 'A-2', google_email: null, ready: false },
    ],
    subjects: [{
      course_id: 9,
      code: 'MTH',
      name: 'Mathematics',
      teacher: 'Mohamed',
      teacher_user_id: 20,
      teacher_google_email: null,
      linked: false,
      google_course_id: null,
      google_name: null,
      state: null,
      link: null,
      enrollment_code: null,
      last_error: null,
      teacher_added: false,
      students_enrolled: 0,
      students_missing: 2,
    }],
  }

  beforeEach(() => {
    apiMock.mockReset()
    notifyError.mockReset()
    notifySuccess.mockReset()
    notifyInfo.mockReset()
    confirmAction.mockClear()

    apiMock.mockImplementation((path) => {
      if (path === '/google-classroom/settings') return Promise.resolve({ data: settings })
      if (path === '/course-sections') {
        return Promise.resolve({ data: [{ id: 5, class_name: 'G12', academic_year: '2026-2027' }] })
      }
      if (path.startsWith('/google-classroom/classes/5/suggest-emails')) return Promise.resolve({ data: [] })
      if (path.startsWith('/google-classroom/classes/')) return Promise.resolve({ data: status })

      return Promise.resolve({ data: {} })
    })
  })

  async function open({ pickClass = true } = {}) {
    const wrapper = mount(GoogleClassroom)
    await flushPromises()

    if (pickClass) {
      await wrapper.find('select').setValue('5')
      await flushPromises()
    }

    return wrapper
  }

  it('says plainly that nothing reaches Google yet', async () => {
    const wrapper = await open({ pickClass: false })

    expect(wrapper.text()).toContain('وضع التجربة')
    expect(wrapper.text()).toContain('vis.edu.ly')
  })

  it('marks a student with no Workspace address as not ready', async () => {
    const wrapper = await open()
    const rows = wrapper.findAll('tbody tr')

    expect(wrapper.text()).toContain('جاهزون')
    expect(rows.some((row) => row.text().includes('Omar') && row.text().includes('بلا بريد'))).toBe(true)
  })

  it('keeps the save button quiet until an address is actually changed', async () => {
    const wrapper = await open()
    const save = wrapper.findAll('button').find((b) => b.text().includes('حفظ العناوين'))

    expect(save.attributes('disabled')).toBeDefined()

    await wrapper.findAll('input[type="email"]').at(-1).setValue('omar@vis.edu.ly')

    expect(
      wrapper.findAll('button').find((b) => b.text().includes('حفظ العناوين')).attributes('disabled'),
    ).toBeUndefined()
  })

  it('sends every address in one batch, blanks included as null', async () => {
    const wrapper = await open()
    await wrapper.findAll('input[type="email"]').at(-1).setValue('omar@vis.edu.ly')
    apiMock.mockClear()

    await wrapper.findAll('button').find((b) => b.text().includes('حفظ العناوين')).trigger('click')
    await flushPromises()

    const [path, options] = apiMock.mock.calls[0]
    const body = JSON.parse(options.body)

    expect(path).toBe('/google-classroom/emails')
    expect(options.method).toBe('PUT')
    expect(body.emails).toEqual(expect.arrayContaining([
      { user_id: 11, google_email: 'ali@vis.edu.ly' },
      { user_id: 12, google_email: 'omar@vis.edu.ly' },
      { user_id: 20, google_email: null },
    ]))
  })

  it('offers to create the course while the subject is unlinked', async () => {
    const wrapper = await open()
    apiMock.mockClear()

    await wrapper.findAll('td.actions button').find((b) => b.text().includes('إنشاء الفصل')).trigger('click')
    await flushPromises()

    expect(apiMock).toHaveBeenCalledWith(
      '/google-classroom/courses/9',
      expect.objectContaining({ method: 'POST' }),
    )
  })

  it('only ticks the students who can actually be enrolled', async () => {
    const linked = {
      ...status,
      subjects: [{ ...status.subjects[0], linked: true, google_course_id: 'g-1', state: 'PROVISIONED' }],
    }
    apiMock.mockImplementation((path) => {
      if (path === '/google-classroom/settings') return Promise.resolve({ data: settings })
      if (path === '/course-sections') {
        return Promise.resolve({ data: [{ id: 5, class_name: 'G12', academic_year: '2026-2027' }] })
      }
      if (path.startsWith('/google-classroom/classes/')) return Promise.resolve({ data: linked })

      return Promise.resolve({ data: { added: ['ali@vis.edu.ly'], invited: [], skipped: [] } })
    })

    const wrapper = await open()
    await wrapper.findAll('td.actions button').find((b) => b.text().includes('إضافة الطلبة')).trigger('click')
    await flushPromises()

    const boxes = wrapper.findAll('.gc-picker input[type="checkbox"]')
    expect(boxes).toHaveLength(2)
    expect(boxes[0].element.checked).toBe(true)
    // No address, so it cannot be ticked at all.
    expect(boxes[1].element.checked).toBe(false)
    expect(boxes[1].attributes('disabled')).toBeDefined()

    apiMock.mockClear()
    await wrapper.findAll('button').find((b) => b.text().includes('تسجيل المحدّدين')).trigger('click')
    await flushPromises()

    const body = JSON.parse(apiMock.mock.calls[0][1].body)
    expect(body.student_profile_ids).toEqual([1])
  })

  it('asks before archiving, and stops if the answer is no', async () => {
    const linked = {
      ...status,
      subjects: [{ ...status.subjects[0], linked: true, google_course_id: 'g-1' }],
    }
    apiMock.mockImplementation((path) => {
      if (path === '/google-classroom/settings') return Promise.resolve({ data: settings })
      if (path === '/course-sections') {
        return Promise.resolve({ data: [{ id: 5, class_name: 'G12', academic_year: '2026-2027' }] })
      }
      if (path.startsWith('/google-classroom/classes/')) return Promise.resolve({ data: linked })

      return Promise.resolve({ data: {} })
    })
    confirmAction.mockResolvedValueOnce(false)

    const wrapper = await open()
    apiMock.mockClear()

    await wrapper.findAll('td.actions button').find((b) => b.text().includes('أرشفة')).trigger('click')
    await flushPromises()

    expect(confirmAction).toHaveBeenCalled()
    expect(apiMock).not.toHaveBeenCalledWith(
      expect.stringContaining('/archive'),
      expect.anything(),
    )
  })

  it('shows the setup steps on request', async () => {
    const wrapper = await open({ pickClass: false })

    expect(wrapper.find('.guide').exists()).toBe(false)

    await wrapper.findAll('button').find((b) => b.text().includes('كيف أربط البريد؟')).trigger('click')

    expect(wrapper.findAll('.guide li').length).toBeGreaterThan(5)
    expect(wrapper.text()).toContain('GOOGLE_CLASSROOM_ENABLED=true')
  })
})
