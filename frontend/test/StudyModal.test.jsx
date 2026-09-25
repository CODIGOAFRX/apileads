import { describe, expect, it, vi, beforeEach } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithApp } from './helpers.jsx'

vi.mock('../src/api/endpoints.js', () => ({
  catalogApi: { createStudy: vi.fn(), updateStudy: vi.fn(), deleteStudy: vi.fn(), addAlias: vi.fn(), deleteAlias: vi.fn() },
  leadsApi: { create: vi.fn(), update: vi.fn() },
}))

const { catalogApi, leadsApi } = await import('../src/api/endpoints.js')
const { StudyModal } = await import('../src/features/settings/CatalogSettings.jsx')
const { LeadFormModal } = await import('../src/features/leads/LeadFormModal.jsx')

describe('Crear estudios de interés (comerciales)', () => {
  beforeEach(() => vi.clearAllMocks())

  it('una comercial crea un curso corto con lo básico', async () => {
    catalogApi.createStudy.mockResolvedValue({ id: 40, nombre: 'Curso de Procreate' })
    const onSaved = vi.fn()
    renderWithApp(<StudyModal study={{}} onClose={() => {}} onSaved={onSaved} />)
    const user = userEvent.setup()

    expect(screen.getByLabelText('Tipo').value).toBe('CURSO')
    expect(screen.queryByLabelText('Orden')).toBeNull()
    expect(screen.queryByText(/Se imparte en/)).toBeNull()
    expect(screen.queryByText(/Activo/)).toBeNull()
    expect(screen.queryByRole('button', { name: /Eliminar/ })).toBeNull()

    await user.type(screen.getByLabelText('Nombre'), 'Curso de Procreate')
    await user.click(screen.getByRole('button', { name: 'Crear estudio' }))

    const payload = catalogApi.createStudy.mock.calls[0][0]
    expect(payload).toMatchObject({ nombre: 'Curso de Procreate', tipo: 'CURSO' })
    expect(payload).not.toHaveProperty('orden')
    expect(payload).not.toHaveProperty('activo')
    expect(payload).not.toHaveProperty('centroIds')
    expect(onSaved).toHaveBeenCalledWith({ id: 40, nombre: 'Curso de Procreate' })
  })

  it('si ya existe, ofrece usar ese en lugar de duplicarlo', async () => {
    const existing = { id: 2, nombre: 'Grado Diseño Gráfico' }
    catalogApi.createStudy.mockRejectedValue(Object.assign(new Error('Ya existe'), { status: 409, details: { estudio: existing } }))
    const onSaved = vi.fn()
    renderWithApp(<StudyModal study={{}} onClose={() => {}} onSaved={onSaved} />)
    const user = userEvent.setup()

    await user.type(screen.getByLabelText('Nombre'), 'grado diseño grafico')
    await user.click(screen.getByRole('button', { name: 'Crear estudio' }))
    await user.click(await screen.findByRole('button', { name: /Usar «Grado Diseño Gráfico»/ }))

    expect(onSaved).toHaveBeenCalledWith(existing)
  })

  it('administración sigue viendo todas las opciones', () => {
    renderWithApp(<StudyModal study={{}} onClose={() => {}} onSaved={() => {}} />, { isAdmin: true })
    expect(screen.getByLabelText('Orden')).toBeTruthy()
    expect(screen.getByText(/Se imparte en/)).toBeTruthy()
  })

  it('desde el alta de un lead, el estudio creado queda marcado', async () => {
    catalogApi.createStudy.mockResolvedValue({ id: 2, nombre: 'Grado Diseño Gráfico' })
    leadsApi.create.mockResolvedValue({ lead: { id: 1 }, accion: 'CREADO' })
    const reloadCatalog = vi.fn(async () => {})
    renderWithApp(<LeadFormModal section="pendientes" onClose={() => {}} onSaved={() => {}} onOpenExisting={() => {}} />, { reloadCatalog })
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: /No está en la lista: crear estudio/ }))
    await user.type(screen.getByLabelText('Nombre', { selector: '[data-autofocus][placeholder*="Procreate"]' }), 'Grado Diseño Gráfico')
    await user.click(screen.getByRole('button', { name: 'Crear estudio' }))
    expect(reloadCatalog).toHaveBeenCalled()

    const study = screen.getByRole('button', { name: /Grado Diseño Gráfico/ })
    expect(study.getAttribute('aria-pressed')).toBe('true')

    await user.type(screen.getAllByLabelText('Nombre')[0], 'Eva')
    await user.click(screen.getByRole('radio', { name: 'Web' }))
    await user.click(screen.getByRole('button', { name: /Crear lead/ }))
    expect(leadsApi.create.mock.calls[0][0].estudioIds).toEqual([2])
  })
})
