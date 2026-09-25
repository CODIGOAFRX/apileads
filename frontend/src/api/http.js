// Cliente HTTP mínimo sobre fetch: JSON, token, errores legibles y descargas.
import { clearSession, loadSession } from '../auth/session.js'

export class ApiError extends Error {
  constructor(message, status, details = {}) {
    super(message)
    this.status = status
    this.details = details
  }
}

let onUnauthorized = () => {}
export function setUnauthorizedHandler(handler) {
  onUnauthorized = handler
}

function headers(extra = {}) {
  const session = loadSession()
  return {
    Accept: 'application/json',
    ...(session ? { Authorization: `Bearer ${session.token}` } : {}),
    ...extra,
  }
}

export function toQuery(params = {}) {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '' || value === false) continue
    if (Array.isArray(value)) {
      if (value.length) search.set(key, value.join(','))
    } else {
      search.set(key, value === true ? '1' : String(value))
    }
  }
  const text = search.toString()
  return text ? `?${text}` : ''
}

async function handle(response) {
  if (response.status === 204) return null
  const isJson = (response.headers.get('Content-Type') || '').includes('application/json')
  const body = isJson ? await response.json().catch(() => null) : null
  if (response.ok) return body

  if (response.status === 401) {
    clearSession()
    onUnauthorized()
  }
  const message = body?.error || (response.status >= 500
    ? 'El servidor no ha podido completar la operación. Inténtalo de nuevo.'
    : `Error ${response.status}`)
  throw new ApiError(message, response.status, body?.detalles || {})
}

// Si el servidor no contesta, se avisa en lugar de dejar la pantalla esperando para siempre.
export const TIMEOUT_MS = 30_000
export const UPLOAD_TIMEOUT_MS = 180_000

/** fetch con tiempo máximo; los fallos de red se convierten en un ApiError legible. */
async function request(url, options, timeout) {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), timeout)
  try {
    return await fetch(url, { ...options, signal: controller.signal })
  } catch (error) {
    if (error?.name === 'AbortError') {
      throw new ApiError('El servidor está tardando demasiado en responder. Inténtalo de nuevo en un momento.', 0)
    }
    throw new ApiError('Sin conexión con el servidor. Revisa tu conexión a internet.', 0)
  } finally {
    clearTimeout(timer)
  }
}

async function send(method, path, body, isForm = false) {
  const response = await request(`/api${path}`, {
    method,
    headers: headers(body && !isForm ? { 'Content-Type': 'application/json' } : {}),
    body: body ? (isForm ? body : JSON.stringify(body)) : undefined,
  }, isForm ? UPLOAD_TIMEOUT_MS : TIMEOUT_MS)
  return handle(response)
}

export const http = {
  get: (path, params) => send('GET', path + toQuery(params)),
  post: (path, body) => send('POST', path, body ?? {}),
  put: (path, body) => send('PUT', path, body ?? {}),
  delete: (path) => send('DELETE', path),
  upload: (path, formData) => send('POST', path, formData, true),

  /** Descarga un fichero respetando el nombre que envía el servidor. */
  async download(path, params, fallbackName = 'descarga') {
    const response = await request(`/api${path}${toQuery(params)}`, { headers: headers() }, UPLOAD_TIMEOUT_MS)
    if (!response.ok) return handle(response)
    const disposition = response.headers.get('Content-Disposition') || ''
    const match = /filename\*=UTF-8''([^;]+)/i.exec(disposition) || /filename="([^"]+)"/i.exec(disposition)
    const name = match ? decodeURIComponent(match[1]) : fallbackName
    const url = URL.createObjectURL(await response.blob())
    const link = Object.assign(document.createElement('a'), { href: url, download: name })
    document.body.appendChild(link)
    link.click()
    link.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  },
}
