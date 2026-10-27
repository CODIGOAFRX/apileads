import { describe, expect, it, vi, beforeEach } from 'vitest'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithApp } from './helpers.jsx'

vi.mock('../src/api/endpoints.js', () => ({
  leadsApi: { create: vi.fn(), update: vi.fn() },
  catalogApi: {},
}))

const { leadsApi } = await import('../src/api/endpoints.js')
const { LeadFormModal } = await import('../src/features/leads/LeadFormModal.jsx')

function open(props = {}, options = {}) {
  const onSaved = vi.fn()
  renderWithApp(<LeadFormModal section="pendientes" onClose={() => {}} onSaved={onSaved} onOpenExisting={() => {}} {...props} />, options)
  return { onSaved, user: userEvent.setup() }
}

const radio = (name) => screen.getByRole('radio', { name })
const checked = (name) => radio(name).getAttribute('aria-checked')

describe('Alta de un lead', () => {
  beforeEach(() => vi.clearAllMocks())

  it('ofrece los diez orígenes y solo se puede elegir uno', async () => {
    const { user } = open()
    const options = within(screen.getByRole('radiogroup', { name: 'Origen' })).getAllByRole('radio')
    expect(options.map((o) => o.textContent)).toEqual([
      'Web', 'Redes sociales', 'Referencias', 'Año pasado', 'Feria', 'Colegios', 'AprendeMás', 'Emagister', 'TuMaster', 'EducaWeb',
    ])
    expect(options.every((o) => o.getAttribute('aria-checked') === 'false')).toBe(true)

    await user.click(radio('Feria'))
    expect(checked('Feria')).toBe('true')
    await user.click(radio('Colegios'))
    expect(checked('Colegios')).toBe('true')
    expect(checked('Feria')).toBe('false')
    expect(options.filter((o) => o.getAttribute('aria-checked') === 'true')).toHaveLength(1)
  })

  it('marca la empresa según el estudio de interés elegido', async () => {
    const { user } = open()
    const company = () => screen.queryByText('Este lead es de')?.nextSibling.textContent

    expect(company()).toBeUndefined()
    await user.click(screen.getByRole('button', { name: 'Gestión de recepción' }))
    expect(company()).toBe('Grupo EFP')
    await user.click(screen.getByRole('button', { name: 'Grado Diseño Moda' }))
    expect(company()).toBe('Estación Diseño')
    await user.click(screen.getByRole('button', { name: 'Grado Diseño Moda' }))
    expect(company()).toBe('Grupo EFP')
  })

  it('quien solo trabaja con Estación Diseño ve solo sus estudios y no puede guardar sin uno', async () => {
    const { user } = open({}, { empresa: 'ESTACION' })

    expect(screen.queryByRole('button', { name: 'Gestión de recepción' })).toBeNull()
    expect(screen.queryByText('Este lead es de')).toBeNull()
    expect(screen.queryByRole('alert')).toBeNull()

    await user.type(screen.getByLabelText('Nombre'), 'Eva')
    await user.click(radio('Web'))
    await user.click(screen.getByRole('button', { name: /Crear lead/ }))
    expect(screen.getByRole('alert').textContent).toMatch(/solo se dan de alta leads de Estación Diseño/)
    expect(leadsApi.create).not.toHaveBeenCalled()

    leadsApi.create.mockResolvedValue({ lead: { id: 9, nombre: 'Eva' } })
    await user.click(screen.getByRole('button', { name: 'Grado Diseño Moda' }))
    await user.click(screen.getByRole('button', { name: /Crear lead/ }))
    expect(leadsApi.create).toHaveBeenCalledTimes(1)
  })

  it('ya no pide campaña ni formulario', () => {
    open()
    expect(screen.queryByText(/campaña/i)).toBeNull()
    expect(screen.queryByText(/formulario/i)).toBeNull()
  })

  it('no deja crearlo sin origen', async () => {
    const { user } = open()
    await user.type(screen.getByLabelText('Nombre'), 'Eva')
    await user.click(screen.getByRole('button', { name: /Crear lead/ }))

    expect(screen.getByRole('alert').textContent).toMatch(/Elige de dónde viene el lead/)
    expect(leadsApi.create).not.toHaveBeenCalled()

    // Al elegir el origen desaparece el aviso.
    await user.click(radio('Web'))
    expect(screen.queryByRole('alert')).toBeNull()
  })

  it('envía el origen elegido', async () => {
    leadsApi.create.mockResolvedValue({ lead: { id: 9, nombre: 'Eva' }, accion: 'CREADO' })
    const { user, onSaved } = open()
    await user.type(screen.getByLabelText('Nombre'), 'Eva')
    await user.type(screen.getByLabelText('Teléfono'), '600123456')
    await user.click(radio('Emagister'))
    await user.click(screen.getByRole('button', { name: /Crear lead/ }))

    expect(leadsApi.create).toHaveBeenCalledTimes(1)
    const payload = leadsApi.create.mock.calls[0][0]
    expect(payload).toMatchObject({ nombre: 'Eva', telefono: '600123456', origen: 'EMAGISTER' })
    expect(payload).not.toHaveProperty('campana')
    expect(onSaved).toHaveBeenCalledWith({ id: 9, nombre: 'Eva' })
  })

  it('un lead antiguo «Sin indicar» se puede guardar sin tocar el origen', async () => {
    leadsApi.update.mockResolvedValue({ id: 5, nombre: 'Ana' })
    const lead = { id: 5, nombre: 'Ana', apellidos: '', email: 'ana@example.com', telefono: '', estudios: [], origen: 'OTRO', comentarios: '' }
    const { user } = open({ lead })
    expect(screen.getByText(/Lead antiguo sin origen/)).toBeTruthy()

    await user.click(screen.getByRole('button', { name: /Guardar cambios/ }))
    expect(leadsApi.update).toHaveBeenCalledWith(5, expect.objectContaining({ origen: 'OTRO' }))
  })

  it('si el servidor dice que ya existe, lo ofrece en vez de duplicarlo', async () => {
    const duplicate = { id: 3, nombre: 'Eva', apellidos: 'Pérez', nombreCompleto: 'Eva Pérez', telefono: '600 12 34 56', email: '', estadoLabel: 'Pendiente' }
    leadsApi.create.mockRejectedValue(Object.assign(new Error('Ya existe'), { status: 409, details: { duplicado: duplicate } }))
    const { user } = open()
    await user.type(screen.getByLabelText('Nombre'), 'Eva')
    await user.click(radio('Web'))
    await user.click(screen.getByRole('button', { name: /Crear lead/ }))

    expect(await screen.findByText(/Ya existe Eva Pérez/)).toBeTruthy()
    expect(screen.getByRole('button', { name: /Añadir estos datos al existente/ })).toBeTruthy()
  })
})
