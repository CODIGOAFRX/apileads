import { afterEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ErrorBoundary } from '../src/components/ErrorBoundary.jsx'
import { ApiError, TIMEOUT_MS, http, setUnauthorizedHandler } from '../src/api/http.js'
import { saveSession, loadSession } from '../src/auth/session.js'

describe('Red de seguridad de las pantallas', () => {
  it('si una pantalla falla muestra un aviso con «Reintentar» en vez de quedarse en blanco', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    let broken = true
    function Screen() {
      if (broken) throw new Error('fallo inesperado')
      return <p>Todo bien</p>
    }
    render(<ErrorBoundary><Screen /></ErrorBoundary>)
    expect(screen.getByRole('alert').textContent).toMatch(/Algo ha fallado en esta pantalla/)

    broken = false
    await userEvent.setup().click(screen.getByRole('button', { name: /Reintentar/ }))
    expect(screen.getByText('Todo bien')).toBeTruthy()
  })
})

describe('Cliente HTTP', () => {
  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  const json = (status, body) => new Response(body === undefined ? null : JSON.stringify(body), {
    status, headers: { 'Content-Type': 'application/json' },
  })

  it('si el servidor no contesta, avisa en lugar de esperar para siempre', async () => {
    vi.useFakeTimers()
    vi.stubGlobal('fetch', vi.fn((_url, { signal }) => new Promise((_resolve, reject) => {
      signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })))
    })))
    const pending = http.get('/leads').catch((error) => error)
    await vi.advanceTimersByTimeAsync(TIMEOUT_MS + 1)
    const error = await pending
    expect(error).toBeInstanceOf(ApiError)
    expect(error.message).toMatch(/tardando demasiado/)
  })

  it('sin red da un mensaje comprensible', async () => {
    vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new TypeError('Failed to fetch'))))
    await expect(http.get('/leads')).rejects.toMatchObject({ status: 0, message: expect.stringMatching(/Sin conexión/) })
  })

  it('muestra el mensaje del servidor y sus detalles', async () => {
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(json(429, { error: 'Espera 40 s', detalles: { esperaSegundos: 40 } }))))
    await expect(http.post('/leads/1/correo')).rejects.toMatchObject({ status: 429, message: 'Espera 40 s', details: { esperaSegundos: 40 } })
  })

  it('un 500 sin mensaje no enseña detalles técnicos', async () => {
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(new Response('<html>Fatal error</html>', { status: 500 }))))
    await expect(http.get('/leads')).rejects.toMatchObject({ message: expect.stringMatching(/no ha podido completar/) })
  })

  it('con la sesión caducada la cierra y avisa a la aplicación', async () => {
    saveSession({ token: 'abc', expiraEn: 3600, usuario: { id: 1 } }, true)
    expect(loadSession()?.token).toBe('abc')
    const onUnauthorized = vi.fn()
    setUnauthorizedHandler(onUnauthorized)
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(json(401, { error: 'Sesión no válida o caducada' }))))

    await expect(http.get('/leads')).rejects.toMatchObject({ status: 401 })
    expect(onUnauthorized).toHaveBeenCalledTimes(1)
    expect(loadSession()).toBeNull()
  })

  it('manda el token y trata bien las respuestas vacías', async () => {
    saveSession({ token: 'tok-123', expiraEn: 3600, usuario: { id: 1 } }, false)
    const fetchMock = vi.fn(() => Promise.resolve(new Response(null, { status: 204 })))
    vi.stubGlobal('fetch', fetchMock)

    expect(await http.delete('/leads/1/bloqueo')).toBeNull()
    expect(fetchMock.mock.calls[0][1].headers.Authorization).toBe('Bearer tok-123')
  })
})
