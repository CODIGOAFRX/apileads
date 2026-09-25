import { describe, expect, it, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithApp } from './helpers.jsx'

vi.mock('../src/api/endpoints.js', () => ({
  leadsApi: {
    lock: vi.fn(), unlock: vi.fn(() => Promise.resolve()), call: vi.fn(), scheduleCall: vi.fn(),
    whatsapp: vi.fn(() => Promise.resolve()), dial: vi.fn(() => Promise.resolve()),
  },
}))

const { leadsApi } = await import('../src/api/endpoints.js')
const { CallMenu } = await import('../src/features/leads/CallMenu.jsx')

const LEAD = {
  id: 4, nombreCompleto: 'Eva Pérez', telefono: '600 12 34 56', telUrl: 'tel:+34600123456',
  whatsappUrl: 'https://wa.me/34600123456', intentos: 0, estado: 'NUEVO', acciones: ['llamar', 'whatsapp', 'enviar-info'],
}

function open() {
  const onDone = vi.fn()
  const anchor = document.createElement('button')
  document.body.appendChild(anchor)
  renderWithApp(<CallMenu lead={LEAD} anchor={anchor} onClose={() => {}} onDone={onDone} onMail={() => {}} />)
  return { onDone, user: userEvent.setup() }
}

describe('Menú de llamada', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    leadsApi.lock.mockResolvedValue(LEAD)
  })

  it('al abrirse reserva el lead para que nadie más lo llame a la vez', async () => {
    open()
    await waitFor(() => expect(leadsApi.lock).toHaveBeenCalledWith(4))
  })

  it('la tecla 1 registra «No contesta»', async () => {
    leadsApi.call.mockResolvedValue({ ...LEAD, intentos: 1, estado: 'NO_CONTESTA', proximaLlamada: '2026-09-26T07:00:00Z' })
    const { user, onDone } = open()
    await user.keyboard('1')

    expect(leadsApi.call).toHaveBeenCalledWith(4, 'NO_CONTESTA', '')
    await waitFor(() => expect(onDone).toHaveBeenCalledTimes(1))
    expect(onDone.mock.calls[0][1]).toBe('mail-whatsapp')
  })

  it('la nota escrita viaja con el resultado', async () => {
    leadsApi.call.mockResolvedValue({ ...LEAD, estado: 'INTERESADO' })
    const { user } = open()
    await user.type(screen.getByPlaceholderText(/Nota/), 'Quiere visitar el centro')
    await user.click(screen.getByRole('button', { name: /Contesta · interesado/ }))

    expect(leadsApi.call).toHaveBeenCalledWith(4, 'INTERESADO', 'Quiere visitar el centro')
  })

  it('escribir un número en la nota no registra nada', async () => {
    const { user } = open()
    await user.type(screen.getByPlaceholderText(/Nota/), 'llamar a las 3')
    expect(leadsApi.call).not.toHaveBeenCalled()
  })

  it('un doble clic no registra la llamada dos veces', async () => {
    let finish
    leadsApi.call.mockReturnValue(new Promise((resolve) => { finish = resolve }))
    const { user } = open()
    const button = screen.getByRole('button', { name: /Contesta · no interesado/ })
    await user.dblClick(button)
    await user.keyboard('2')
    expect(leadsApi.call).toHaveBeenCalledTimes(1)
    finish({ ...LEAD, estado: 'DESCARTADO' })
  })

  it('si otra comercial lo está llamando, lo dice y no deja registrar', async () => {
    leadsApi.lock.mockRejectedValue(new Error('Nuria está llamando a este lead ahora mismo'))
    const { user } = open()
    expect(await screen.findByText(/Nuria está llamando a este lead/)).toBeTruthy()
    expect(screen.queryByRole('button', { name: /No contesta/ })).toBeNull()
    await user.keyboard('1')
    expect(leadsApi.call).not.toHaveBeenCalled()
  })

  it('pulsar el teléfono queda registrado', async () => {
    const { user } = open()
    const phone = screen.getByRole('link', { name: /600 12 34 56/ })
    expect(phone.getAttribute('href')).toBe('tel:+34600123456')
    phone.addEventListener('click', (event) => event.preventDefault())
    await user.click(phone)
    expect(leadsApi.dial).toHaveBeenCalledWith(4)
  })

  it('agendar pide día y hora y los envía en hora de Madrid', async () => {
    leadsApi.scheduleCall.mockResolvedValue({ ...LEAD, llamadaAgendada: '2026-10-01T15:00:00Z' })
    const { user, onDone } = open()
    await user.keyboard('4')
    const day = screen.getByLabelText('Día')
    await user.clear(day)
    await user.type(day, '2030-10-01')
    await user.type(screen.getByLabelText('Hora'), '17:00')
    await user.click(screen.getByRole('button', { name: /Agendar llamada/ }))

    expect(leadsApi.scheduleCall).toHaveBeenCalledWith(4, '2030-10-01T17:00', '')
    await waitFor(() => expect(onDone).toHaveBeenCalled())
  })
})
