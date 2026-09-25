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
    ultimoEnvio: null, esperaSegundos: 0, intervaloSegundos: 72, simulado: false, ...overrides,
  }
}

function open() {
  const onSent = vi.fn()
  renderWithApp(<MailModal lead={LEAD} section="pendientes" onClose={() => {}} onSent={onSent} />)
  return { onSent, user: userEvent.setup() }
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

    expect(leadsApi.sendMail).toHaveBeenCalledWith(7, undefined, undefined)
    expect(onSent).toHaveBeenCalledWith({ id: 7, envio: { simulado: false, aviso: null } })
    expect(await screen.findByText('Información enviada a eva@example.com')).toBeTruthy()
  })

  it('un error del servidor se muestra y se puede reintentar', async () => {
    leadsApi.mailPreview.mockResolvedValue(preview())
    leadsApi.sendMail.mockRejectedValueOnce(new ApiError('No se pudo enviar el correo: Connection refused', 502))
    const { user } = open()
    await user.click(await screen.findByRole('button', { name: /Enviar correo/ }))

    expect((await screen.findByRole('alert')).textContent).toMatch(/Connection refused/)
    expect(screen.getByRole('button', { name: /Enviar correo/ }).disabled).toBe(false)
  })
})
