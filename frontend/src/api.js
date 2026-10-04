import { ref } from 'vue'

// Same host as the SPA on purpose: a SameSite=Lax session cookie is only sent
// to the same site, and `localhost` and `127.0.0.1` count as different sites.
const apiBaseUrl = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api'

// The session lives in an HttpOnly cookie the browser attaches by itself, so
// there is nothing here for a script to steal. This flag only tells the UI
// whether someone is signed in.
const authenticated = ref(false)

function readCookie(name) {
  const match = document.cookie.match(new RegExp(`(^|; )${name}=([^;]*)`))
  return match ? decodeURIComponent(match[2]) : null
}

/**
 * Laravel hands the SPA a readable XSRF-TOKEN cookie and expects it echoed back
 * in a header. A forged cross-site request can carry the cookie but cannot read
 * it, so it cannot set the header — which is what stops CSRF.
 */
export async function ensureCsrfCookie(force = false) {
  if (!force && readCookie('XSRF-TOKEN')) return

  await fetch(`${apiBaseUrl.replace(/\/api$/, '')}/sanctum/csrf-cookie`, {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  })
}

function csrfHeader() {
  const value = readCookie('XSRF-TOKEN')
  return value ? { 'X-XSRF-TOKEN': value } : {}
}

const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE']

async function request(path, { method = 'GET', headers = {}, ...rest } = {}) {
  const verb = method.toUpperCase()

  if (MUTATING.includes(verb)) {
    await ensureCsrfCookie()
  }

  const send = () => fetch(`${apiBaseUrl}${path}`, {
    method,
    // Sends the session cookie; without it every request is anonymous.
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...csrfHeader(),
      ...headers,
    },
    ...rest,
  })

  let response = await send()

  // A server restart or an invalidated session can leave a readable CSRF
  // cookie in the browser that no longer belongs to a live session. Refresh
  // it and retry once so the next login does not get stuck behind a 419.
  if (response.status === 419 && MUTATING.includes(verb)) {
    await ensureCsrfCookie(true)
    response = await send()
  }

  const data = await response.json().catch(() => ({}))

  if (response.status === 401) {
    authenticated.value = false
    throw new Error(data.message ?? 'انتهت جلستك. يرجى تسجيل الدخول من جديد.')
  }

  // The session expired and Laravel rotated the CSRF token underneath us.
  if (response.status === 419) {
    authenticated.value = false
    throw new Error('انتهت جلستك. يرجى تسجيل الدخول من جديد.')
  }

  if (!response.ok) throw new Error(data.message ?? 'Request failed')

  authenticated.value = true

  return data
}

async function api(path, options = {}) {
  return request(path, {
    ...options,
    headers: { 'Content-Type': 'application/json', ...options.headers },
  })
}

async function apiUpload(path, formData, options = {}) {
  // No Content-Type: the browser sets the multipart boundary itself.
  return request(path, { ...options, method: 'POST', body: formData })
}

/**
 * For endpoints that answer with a file rather than JSON.
 */
async function apiBlob(path, { method = 'GET', body = null } = {}) {
  if (MUTATING.includes(method.toUpperCase())) {
    await ensureCsrfCookie()
  }

  const response = await fetch(`${apiBaseUrl}${path}`, {
    method,
    credentials: 'include',
    headers: {
      Accept: 'application/pdf',
      'X-Requested-With': 'XMLHttpRequest',
      ...(body ? { 'Content-Type': 'application/json' } : {}),
      ...csrfHeader(),
    },
    ...(body ? { body: JSON.stringify(body) } : {}),
  })

  if (!response.ok) {
    // The server explains a refusal in JSON even when a PDF was asked for.
    const detail = await response.json().catch(() => null)

    throw new Error(detail?.message ?? 'تعذّر تحميل الملف.')
  }

  return response.blob()
}

export function useApi() {
  return { apiBaseUrl, authenticated, api, apiUpload, apiBlob, ensureCsrfCookie }
}
