import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import Classroom from './Classroom.vue'

const apiMock = vi.fn()
const apiUploadMock = vi.fn()
const apiBlobMock = vi.fn()

vi.mock('../../api.js', () => ({
  useApi: () => ({
    api: apiMock,
    apiUpload: apiUploadMock,
    apiBlob: apiBlobMock,
    apiBaseUrl: '',
    authenticated: { value: true },
  }),
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
 * The class wall. The tests care about what reaches the server: a post that
 * carries files must not go live before they do, and paging must ask for the
 * next page rather than reloading the first.
 */
describe('Classroom', () => {
  const courses = [
    { id: 7, code: 'MTH', name: 'Mathematics', class_name: 'G12', academic_year: '2026-2027', teacher: 'Mohamed', can_post: true, unread: 3 },
    { id: 8, code: 'ENG', name: 'English', class_name: 'G12', academic_year: '2026-2027', teacher: 'Sara', can_post: false, unread: 0 },
  ]

  function post(overrides = {}) {
    return {
      id: 100,
      type: 'announcement',
      title: 'اختبار الأحد',
      body: 'راجعوا الفصل الثالث',
      author: { id: 1, name: 'Mohamed' },
      published_at: '2026-08-13T09:00:00+00:00',
      is_published: true,
      is_pinned: false,
      comments_enabled: true,
      comments_count: 0,
      attachments: [],
      comments: [],
      can_edit: true,
      can_delete: true,
      can_pin: true,
      ...overrides,
    }
  }

  let streamPosts = []
  let nextCursor = null

  beforeEach(() => {
    apiMock.mockReset()
    apiUploadMock.mockReset()
    apiBlobMock.mockReset()
    notifyError.mockReset()
    notifySuccess.mockReset()
    confirmDelete.mockClear()
    confirmDelete.mockResolvedValue(true)

    streamPosts = [post()]
    nextCursor = null

    apiMock.mockImplementation((path, options = {}) => {
      if (path === '/classroom/courses') return Promise.resolve({ data: courses })
      if (path.includes('/seen')) return Promise.resolve({ data: {} })
      if (path.includes('/stream')) {
        return Promise.resolve({
          data: {
            course: { id: 7, name: 'Mathematics', class_name: 'G12', academic_year: '2026-2027', teacher: 'Mohamed', can_post: true },
            posts: path.includes('before=') ? [post({ id: 80, title: 'أقدم' })] : streamPosts,
            next_cursor: path.includes('before=') ? null : nextCursor,
          },
        })
      }
      if (path.includes('/comments') && options.method === 'POST') {
        return Promise.resolve({ data: { id: 501, body: 'شكراً', user: { id: 9, name: 'Ali' }, created_at: null, can_delete: true } })
      }
      if (path.includes('/posts') && options.method === 'POST') {
        return Promise.resolve({ data: { id: 900 } })
      }

      return Promise.resolve({ data: {} })
    })

    apiUploadMock.mockResolvedValue({ data: { id: 1, name: 'f.pdf', size: 10, is_pdf: true } })
  })

  async function open() {
    const wrapper = mount(Classroom)
    await flushPromises()

    return wrapper
  }

  it('opens the first subject and shows what is unread on the others', async () => {
    const wrapper = await open()

    expect(wrapper.text()).toContain('Mathematics')
    expect(wrapper.text()).toContain('English')
    // Opening a subject clears its own badge.
    expect(wrapper.find('.course-chip-unread').exists()).toBe(false)
  })

  it('marks the stream as read on opening it', async () => {
    await open()

    expect(apiMock).toHaveBeenCalledWith('/classroom/courses/7/seen', expect.objectContaining({ method: 'POST' }))
  })

  it('publishes a text-only post in one step', async () => {
    const wrapper = await open()

    await wrapper.find('textarea').setValue('امتحان الأحد')
    apiMock.mockClear()

    await wrapper.findAll('button').find((b) => b.text() === 'نشر').trigger('click')
    await flushPromises()

    const [path, options] = apiMock.mock.calls[0]
    const body = JSON.parse(options.body)

    expect(path).toBe('/classroom/courses/7/posts')
    expect(body.body).toBe('امتحان الأحد')
    // Nothing to wait for, so it goes live immediately.
    expect(body.publish).toBe(true)
  })

  it('holds a post with files back until they have uploaded', async () => {
    const wrapper = await open()

    await wrapper.find('textarea').setValue('ورقة العمل')

    const input = wrapper.find('input[type="file"]')
    Object.defineProperty(input.element, 'files', {
      value: [new File(['x'], 'worksheet.pdf', { type: 'application/pdf' })],
      configurable: true,
    })
    await input.trigger('change')
    apiMock.mockClear()

    await wrapper.findAll('button').find((b) => b.text() === 'نشر').trigger('click')
    await flushPromises()

    const created = JSON.parse(apiMock.mock.calls[0][1].body)
    expect(created.publish).toBe(false)

    expect(apiUploadMock).toHaveBeenCalledWith('/classroom/posts/900/attachments', expect.any(FormData))
    // Published only after the file landed.
    expect(apiMock).toHaveBeenCalledWith('/classroom/posts/900/publish', expect.objectContaining({ method: 'POST' }))
  })

  it('refuses to post nothing at all', async () => {
    const wrapper = await open()
    apiMock.mockClear()

    await wrapper.findAll('button').find((b) => b.text() === 'نشر').trigger('click')
    await flushPromises()

    expect(notifyError).toHaveBeenCalled()
    expect(apiMock).not.toHaveBeenCalled()
  })

  it('asks for the next page by cursor instead of reloading the first', async () => {
    nextCursor = 90
    const wrapper = await open()
    apiMock.mockClear()

    await wrapper.findAll('button').find((b) => b.text().includes('عرض منشورات أقدم')).trigger('click')
    await flushPromises()

    expect(apiMock).toHaveBeenCalledWith('/classroom/courses/7/stream?before=90')
    // Appended, not replaced.
    expect(wrapper.findAll('.class-post')).toHaveLength(2)
  })

  it('shows attachments with their size and downloads on click', async () => {
    streamPosts = [post({ attachments: [{ id: 55, name: 'worksheet.pdf', size: 2048, mime: 'application/pdf', is_pdf: true }] })]
    apiBlobMock.mockResolvedValue(new Blob(['x']))
    const wrapper = await open()

    expect(wrapper.text()).toContain('worksheet.pdf')
    expect(wrapper.text()).toContain('2 KB')

    // jsdom has no real download; only the request matters here.
    global.URL.createObjectURL = vi.fn(() => 'blob:x')
    global.URL.revokeObjectURL = vi.fn()

    await wrapper.find('.attachment').trigger('click')
    await flushPromises()

    expect(apiBlobMock).toHaveBeenCalledWith('/classroom/attachments/55')
  })

  it('sends a comment and counts it without refetching the stream', async () => {
    const wrapper = await open()
    await wrapper.find('.comment-box input').setValue('شكراً')
    apiMock.mockClear()

    await wrapper.findAll('button').find((b) => b.text() === 'إرسال').trigger('click')
    await flushPromises()

    expect(apiMock).toHaveBeenCalledWith('/classroom/posts/100/comments', expect.objectContaining({ method: 'POST' }))
    expect(apiMock).not.toHaveBeenCalledWith(expect.stringContaining('/stream'), undefined)
    expect(wrapper.text()).toContain('شكراً')
  })

  it('offers no comment box when the author switched comments off', async () => {
    streamPosts = [post({ comments_enabled: false })]
    const wrapper = await open()

    expect(wrapper.find('.comment-box').exists()).toBe(false)
  })

  it('asks before deleting a post', async () => {
    confirmDelete.mockResolvedValueOnce(false)
    const wrapper = await open()
    apiMock.mockClear()

    await wrapper.findAll('.class-post button').find((b) => b.text() === 'حذف').trigger('click')
    await flushPromises()

    expect(confirmDelete).toHaveBeenCalled()
    expect(apiMock).not.toHaveBeenCalled()
  })

  it('marks a draft as such and gives it no comment box', async () => {
    streamPosts = [post({ is_published: false, published_at: null })]
    const wrapper = await open()

    expect(wrapper.text()).toContain('مسودة')
    expect(wrapper.find('.comment-box').exists()).toBe(false)
  })

  it('says plainly when there is nothing to show', async () => {
    apiMock.mockImplementation((path) => {
      if (path === '/classroom/courses') return Promise.resolve({ data: [] })

      return Promise.resolve({ data: {} })
    })

    const wrapper = await open()

    expect(wrapper.text()).toContain('لا توجد مواد متاحة لك بعد.')
  })
})
