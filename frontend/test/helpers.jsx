// Pinta un componente con el contexto de la aplicación (usuario, catálogo y avisos).
import { render } from '@testing-library/react'
import { AppContext } from '../src/AppContext.js'
import { ToastProvider } from '../src/components/Toasts.jsx'

export const ORIGINS = [
  ['WEB', 'Web'], ['REDES_SOCIALES', 'Redes sociales'], ['REFERENCIAS', 'Referencias'], ['ANO_PASADO', 'Año pasado'],
  ['FERIA', 'Feria'], ['COLEGIOS', 'Colegios'], ['APRENDEMAS', 'AprendeMás'], ['EMAGISTER', 'Emagister'],
  ['TUMASTER', 'TuMaster'], ['EDUCAWEB', 'EducaWeb'],
].map(([id, label]) => ({ id, label, seleccionable: true })).concat({ id: 'OTRO', label: 'Sin indicar', seleccionable: false })

export const CATALOG = {
  estudios: [
    { id: 1, nombre: 'Grado Diseño Moda', color: '#FF79F5', tipo: 'GRADO', activo: true },
    { id: 2, nombre: 'Grado Diseño Gráfico', color: '#FF7A78', tipo: 'GRADO', activo: true },
  ],
  centros: [{ id: 1, nombre: 'Estación Diseño Granada', activo: true }],
  origenes: ORIGINS,
  tipos: { GRADO: 'Grado', CFGS: 'CFGS', MASTER: 'Máster', CURSO: 'Curso', OTRO: 'Otro' },
  areas: { GRAFICO: 'Gráfico', MODA: 'Moda', ILUSTRACION: 'Ilustración' },
}

export function renderWithApp(ui, { isAdmin = false, reloadCatalog = async () => {} } = {}) {
  const context = {
    user: { id: 2, nombre: 'Marta Ruiz', email: 'marta@estaciondiseno.es', rol: isAdmin ? 'ADMIN' : 'COMERCIAL' },
    isAdmin,
    catalog: CATALOG,
    reloadCatalog,
    logout: () => {},
  }
  return render(
    <AppContext.Provider value={context}>
      <ToastProvider>{ui}</ToastProvider>
    </AppContext.Provider>,
  )
}
