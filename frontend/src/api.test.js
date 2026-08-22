import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useApi } from './api.js'

/**
 * Every request passes through this client, so what it does about credentials
 * is the whole of the SPA's authentication story.
 *
 * The session lives in an HttpOnly cookie: the browser attaches it, and nothing
 * here can read it. That is the point — a script injected into the page has no
 * credential to steal.
 */
describe('api client', () => {
  const { api, authenticated } = useApi()

  beforeEach(() => {
    localStorage.clear()
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    authenticated.value = false
    vi.restoreAllMocks()
  })

  function respondWith(status, body = {}) {
    return vi.fn().mockResolvedValue({
      ok: status >= 200 && status < 300,
      status,
      json: () => Promise.resolve(body),
    })
  }

  it('sends the session cookie on every request', async () => {
    globalThis.fetch = respondWith(200, { data: [] })

    await api('/students')

    const [, options] = globalThis.fetch.mock.calls[0]
    expect(options.credentials).toBe('include')
  })

  it('never sends an Authorization header', async () => {
    globalThis.fetch = respondWith(200, { data: [] })

    await api('/students')

    const [, options] = globalThis.fetch.mock.calls[0]
    expect(options.headers.Authorization).toBeUndefined()
  })

  // Nothing readable is stored, so an injected script has nothing to exfiltrate.
  it('puts no credential in localStorage', async () => {
    globalThis.fetch = respondWith(200, { user: { id: 1 } })

    await api('/login', { method: 'POST', body: '{}' })

    expect(localStorage.getItem('auth_token')).toBeNull()
    expect(Object.keys(localStorage)).toHaveLength(0)
  })

  it('fetches the csrf cookie before a write and echoes it back', async () => {
    globalThis.fetch = vi.fn().mockImplementation((url) => {
      if (String(url).includes('csrf-cookie')) {
        document.cookie = 'XSRF-TOKEN=csrf-value-123; path=/'
        return Promise.resolve({ ok: true, status: 204, json: () => Promise.resolve({}) })
      }
      return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) })
    })

    await api('/students', { method: 'POST', body: '{}' })

    const [csrfUrl] = globalThis.fetch.mock.calls[0]
    expect(String(csrfUrl)).toContain('/sanctum/csrf-cookie')

    const [, options] = globalThis.fetch.mock.calls[1]
    expect(options.headers['X-XSRF-TOKEN']).toBe('csrf-value-123')
  })

  it('does not go looking for a csrf cookie on a read', async () => {
    globalThis.fetch = respondWith(200, {})

    await api('/students')

    expect(globalThis.fetch).toHaveBeenCalledTimes(1)
    expect(String(globalThis.fetch.mock.calls[0][0])).not.toContain('csrf-cookie')
  })

  it('reuses a csrf cookie it already holds', async () => {
    document.cookie = 'XSRF-TOKEN=already-here; path=/'
    globalThis.fetch = respondWith(200, {})

    await api('/students', { method: 'POST', body: '{}' })

    expect(globalThis.fetch).toHaveBeenCalledTimes(1)
    expect(globalThis.fetch.mock.calls[0][1].headers['X-XSRF-TOKEN']).toBe('already-here')
  })

  it('marks the session ended when the server rejects it', async () => {
    authenticated.value = true
    globalThis.fetch = respondWith(401, { message: 'Unauthenticated.' })

    await expect(api('/students')).rejects.toThrow()
    expect(authenticated.value).toBe(false)
  })

  // 419 is Laravel's answer to a stale session or CSRF token.
  it('treats an expired session as a sign-out', async () => {
    authenticated.value = true
    globalThis.fetch = respondWith(419, {})

    await expect(api('/students')).rejects.toThrow(/جلستك/)
    expect(authenticated.value).toBe(false)
  })

  it('throws the server message on an ordinary failure', async () => {
    globalThis.fetch = respondWith(422, { message: 'الحقل مطلوب' })

    await expect(api('/students')).rejects.toThrow('الحقل مطلوب')
  })

  it('talks to the api on the same site as the page', async () => {
    const { apiBaseUrl } = useApi()

    // A SameSite cookie is not sent to a different site, and localhost and
    // 127.0.0.1 count as different sites.
    expect(apiBaseUrl).not.toContain('127.0.0.1')
  })
})
