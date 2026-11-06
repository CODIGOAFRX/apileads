import { describe, expect, it, vi, beforeEach } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithApp } from './helpers.jsx'

vi.mock('../src/api/endpoints.js', () => ({
  leadsApi: { mailPreview: vi.fn(), sendMail: vi.fn(), whatsapp: vi.fn(() => Promise.resolve()) },
  mailApi: { download: vi.fn() },
}))

const { leadsApi } = await import('../src/api/endpoints.js')
const { ApiError } = await import('../src/api/http.js')
const { MailModal } = await import('../src/features/leads/FollowUpModals.jsx')

const LEAD = { id: 7, nombre: 'Eva', apellidos: 'Pérez', telefono: '600123456', estudios: [{ id: 2 }], whatsappUrl: 'https://wa.me/34600123456' }

function preview(overrides = {}) {
  return {
    de: 'marta@estaciondiseno.es', para: 'eva@example.com', asunto: 'Información', html: '<p>Hola Eva</p>',
    adjuntos: [{ id: 1, nombre: 'Dossier Grado Diseño Gráfico 26-27.pdf', tamano: 771692 }],
    ultimoEnvio: null, esperaSegundos: 0, intervaloSegundos: 72, simulado: false,
    estudioId: 2, estudios: [{ id: 2, nombre: 'Grado Diseño Gráfico', color: '#F2C94C', ultimoEnvio: null }], ultimoEnvioEstudio: null,
    ...overrides,
  }
}

function open(lead = LEAD) {
  const onSent = vi.fn()
  const onProgress = vi.fn()
  renderWithApp(<MailModal lead={lead} section="pendientes" onClose={() => {}} onSent={onSent} onProgress={onProgress} />)
  return { onSent, onProgress, user: userEvent.setup() }
}

describe('Enviar información por correo', () => {
  beforeEach(() => vi.clearAllMocks())

  it('muestra remitente, destinatario y el dossier adjunto', async () => {
    leadsApi.mailPreview.mockResolvedValue(preview())
    open()
    expect(await screen.findByText('marta@estaciondiseno.es')).toBeTruthy()
    expect(screen.getByText('eva@example.com')).toBeTruthy()
    expect(screen.getByText(/Dossier Grado Diseño Gráfico 26-27\.pdf/)).toBeTruthy()
    expect(screen.getByText(/754 KB/)).toBeTruthy()
  })

  it('si no toca enviar todavía, espera con cuenta atrás y luego deja enviar', async () => {
    leadsApi.mailPreview.mockResolvedValue(preview({ esperaSegundos: 1 }))
    open()
    const button = await screen.findByRole('button', { name: /Enviar en 1 s/ })
    expect(button.disabled).toBe(true)
    expect(screen.getByText(/como mucho un correo cada 72 segundos/)).toBeTruthy()

    const ready = await screen.findByRole('button', { name: /Enviar correo/ }, { timeout: 2500 })
    expect(ready.disabled).toBe(false)
  })

  it('si otra comercial acaba de enviar uno, muestra la cuenta atrás en vez de un error', async () => {
    leadsApi.mailPreview.mockResolvedValue(preview())
    leadsApi.sendMail.mockRejectedValue(new ApiError('Espera', 429, { esperaSegundos: 40 }))
    const { user, onSent } = open()
    await user.click(await screen.findByRole('button', { name: /Enviar correo/ }))

    expect(await screen.findByRole('button', { name: /Enviar en (40|39) s/ })).toBeTruthy()
    expect(screen.queryByText('Espera')).toBeNull()
    expect(onSent).not.toHaveBeenCalled()
  })

  it('en modo pruebas avisa de que el correo no sale de verdad', async () => {
    leadsApi.mailPreview.mockResolvedValue(preview({ de: null, simulado: true }))
    open()
    expect(await screen.findByText(/no saldrá de verdad/)).toBeTruthy()
    expect(screen.getByText('Buzón general de la escuela')).toBeTruthy()
  })

  it('envía y avisa al terminar', async () => {
    leadsApi.mailPreview.mockResolvedValue(preview())
    leadsApi.sendMail.mockResolvedValue({ id: 7, envio: { simulado: false, aviso: null } })
    const { user, onSent } = open()
    await user.click(await screen.findByRole('button', { name: /Enviar correo/ }))

    expect(leadsApi.sendMail).toHaveBeenCalledWith(7, undefined, undefined, 2)
    expect(onSent).toHaveBeenCalledWith({ id: 7, envio: { simulado: false, aviso: null } })
    expect(await screen.findByText('Enviada la información de Grado Diseño Gráfico a eva@example.com')).toBeTruthy()
  })

  it('un error del servidor se muestra y se puede reintentar', async () => {
    leadsApi.mailPreview.mockResolvedValue(preview())
    leadsApi.sendMail.mockRejectedValueOnce(new ApiError('No se pudo enviar el correo: Connection refused', 502))
    const { user } = open()
    await user.click(await screen.findByRole('button', { name: /Enviar correo/ }))

    expect((await screen.findByRole('alert')).textContent).toMatch(/Connection refused/)
    expect(screen.getByRole('button', { name: /Enviar correo/ }).disabled).toBe(false)
  })

  it('con dos estudios se elige de cuál va y, tras enviarlo, propone el otro en otro correo', async () => {
    const MODA = { id: 1, nombre: 'Grado Diseño Moda', color: '#E8A0BF', ultimoEnvio: null }
    const GRAFICO = { id: 2, nombre: 'Grado Diseño Gráfico', color: '#F2C94C', ultimoEnvio: null }
    const moda = (extra = {}) => preview({
      estudioId: 1, estudios: [MODA, GRAFICO], adjuntos: [{ id: 3, nombre: 'Dossier Grado Diseño de Moda 26-27.pdf', tamano: 1000 }], ...extra,
    })
    const grafico = () => preview({ estudioId: 2, estudios: [MODA, GRAFICO] })
    leadsApi.mailPreview.mockImplementation((id, estudioId) => Promise.resolve(estudioId === 2 ? grafico() : moda()))
    leadsApi.sendMail.mockResolvedValue({ id: 7, envio: { simulado: false, aviso: null } })
    const { user, onSent, onProgress } = open({ ...LEAD, estudios: [{ id: 1 }, { id: 2 }] })

    // Propone el primero; se cambia al otro y carga su correo y su dossier.
    expect(await screen.findByText(/Dossier Grado Diseño de Moda/)).toBeTruthy()
    expect(screen.getByRole('radio', { name: /Grado Diseño Moda/ }).getAttribute('aria-checked')).toBe('true')
    await user.click(screen.getByRole('radio', { name: /Grado Diseño Gráfico/ }))
    expect(await screen.findByText(/Dossier Grado Diseño Gráfico/)).toBeTruthy()
    expect(leadsApi.mailPreview).toHaveBeenLastCalledWith(7, 2)

    // Se envía el de Gráfico: la ventana sigue abierta con el de Moda, esperando su turno.
    leadsApi.mailPreview.mockImplementation(() => Promise.resolve(moda({
      estudios: [MODA, { ...GRAFICO, ultimoEnvio: '2026-09-15T10:00:00+02:00' }], esperaSegundos: 72,
    })))
    await user.click(screen.getByRole('button', { name: /Enviar Grado Diseño Gráfico/ }))
    expect(leadsApi.sendMail).toHaveBeenCalledWith(7, undefined, undefined, 2)
    expect(onProgress).toHaveBeenCalledWith({ id: 7, envio: { simulado: false, aviso: null } })
    expect(onSent).not.toHaveBeenCalled()
    expect(await screen.findByText(/Dossier Grado Diseño de Moda/)).toBeTruthy()
    expect(leadsApi.mailPreview).toHaveBeenLastCalledWith(7, 1)
    expect(document.querySelector('.ed-mailstudy__sent')?.textContent).toMatch(/enviado/)
    expect(screen.getByRole('button', { name: /Enviar en (72|71) s/ }).disabled).toBe(true)
    expect(screen.getByRole('button', { name: 'Terminar' })).toBeTruthy()
  })
})
