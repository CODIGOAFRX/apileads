import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, render, screen } from '@testing-library/react'

vi.mock('../src/api/endpoints.js', () => ({ leadsApi: { reminders: vi.fn() } }))

const { leadsApi } = await import('../src/api/endpoints.js')
const { CallReminders } = await import('../src/components/CallReminders.jsx')

const NOW = new Date('2026-09-15T10:29:00Z')
const flush = () => act(async () => { await Promise.resolve(); await Promise.resolve() })

describe('Avisos de llamadas agendadas', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] })
    vi.setSystemTime(NOW)
    sessionStorage.clear()
    // Como el servidor: su hora es la de ahora mismo (el reloj simulado avanza).
    leadsApi.reminders.mockImplementation(async () => ({
      ahora: new Date().toISOString(),
      llamadas: [
        { id: 7, nombre: 'Eva García', telefono: '+34 612 34 56 78', hora: '2026-09-15T10:30:00Z', mia: true },
        { id: 8, nombre: 'Luis', telefono: null, hora: '2026-09-15T10:30:00Z', mia: false },
      ],
    }))
  })
  afterEach(() => vi.useRealTimers())

  it('a la hora exacta avisa, refresca el listado y abre la ficha al llamar', async () => {
    const onOpen = vi.fn()
    const refresh = vi.fn()
    window.addEventListener('leads:refresh', refresh)
    render(<CallReminders onOpen={onOpen} />)
    await flush()
    expect(screen.queryByRole('alert')).toBeNull()

    await act(async () => { vi.advanceTimersByTime(60_000) })
    const alert = screen.getByRole('alert')
    expect(alert.textContent).toMatch(/Llamada agendada/)
    expect(alert.textContent).toMatch(/Eva García/)
    expect(screen.queryByText('Luis')).toBeNull()
    expect(refresh).toHaveBeenCalledTimes(1)

    await act(async () => { screen.getByRole('button', { name: /Llamar ahora/ }).click() })
    expect(onOpen).toHaveBeenCalledWith(7)
    expect(screen.queryByRole('alert')).toBeNull()
    window.removeEventListener('leads:refresh', refresh)
  })

  it('si el ordenador se suspende, avisa al despertar', async () => {
    render(<CallReminders onOpen={() => {}} />)
    await flush()
    vi.setSystemTime(new Date('2026-09-15T10:40:00Z'))
    await act(async () => { vi.advanceTimersByTime(15_000) })
    expect(screen.getByRole('alert').textContent).toMatch(/Eva García/)
  })

  it('se puede posponer 5 minutos y vuelve a salir', async () => {
    render(<CallReminders onOpen={() => {}} />)
    await flush()
    await act(async () => { vi.advanceTimersByTime(61_000) })

    await act(async () => { screen.getByRole('button', { name: /En 5 min/ }).click() })
    expect(screen.queryByRole('alert')).toBeNull()

    // El tiempo pasa a saltos, como en el navegador (cada aviso del temporizador es una tarea).
    for (let i = 0; i < 21; i++) await act(async () => { vi.advanceTimersByTime(15_000) })
    expect(screen.getByRole('alert').textContent).toMatch(/Eva García/)
  })

  it('cerrado, no vuelve a salir al recargar la página', async () => {
    const first = render(<CallReminders onOpen={() => {}} />)
    await flush()
    await act(async () => { vi.advanceTimersByTime(61_000) })
    await act(async () => { screen.getByRole('button', { name: /Cerrar el aviso/ }).click() })
    first.unmount()

    render(<CallReminders onOpen={() => {}} />)
    await flush()
    await act(async () => { vi.advanceTimersByTime(20_000) })
    expect(screen.queryByRole('alert')).toBeNull()
  })
})
