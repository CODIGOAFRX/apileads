import { useCallback, useEffect, useMemo, useState } from 'react'
import { authApi, catalogApi } from './api/endpoints.js'
import { setUnauthorizedHandler } from './api/http.js'
import { clearSession, loadSession } from './auth/session.js'
import { LoginPage } from './auth/LoginPage.jsx'
import { AppContext } from './AppContext.js'
import { Icon } from './components/Icon.jsx'
import { ErrorBoundary } from './components/ErrorBoundary.jsx'
import { ToastProvider } from './components/Toasts.jsx'
import { Loading } from './components/ui.jsx'
import { CallReminders } from './components/CallReminders.jsx'
import { LeadsPage } from './features/leads/LeadsPage.jsx'
import { ImportPage } from './features/import/ImportPage.jsx'
import { SettingsPage } from './features/settings/SettingsPage.jsx'
import { ActivityPage } from './features/activity/ActivityPage.jsx'
import isotipo from './assets/isotipo-estacion.png'

const PAGES = [
  { id: 'leads', label: 'Leads', icon: 'list' },
  { id: 'importar', label: 'Importar', icon: 'upload' },
  { id: 'actividad', label: 'Actividad', icon: 'clock', admin: true },
  { id: 'ajustes', label: 'Ajustes', icon: 'settings' },
]

export default function App() {
  const [session, setSession] = useState(() => loadSession())
  const [catalog, setCatalog] = useState(null)
  const [page, setPage] = useState('leads')
  // Lead que abrir al pulsar «Llamar ahora» en un aviso (desde cualquier sección).
  const [leadToOpen, setLeadToOpen] = useState(null)
  const openLead = useCallback((id) => { setPage('leads'); setLeadToOpen(id) }, [])

  const logout = useCallback(() => {
    clearSession()
    setSession(null)
    setCatalog(null)
  }, [])

  useEffect(() => setUnauthorizedHandler(logout), [logout])

  const reloadCatalog = useCallback(() => catalogApi.get().then(setCatalog), [])

  // Solo al iniciar sesión (cambia el token), no cada vez que cambia el objeto de sesión.
  const token = session?.token
  useEffect(() => {
    if (!token) return
    // Refresca los datos del usuario (rol o nombre pueden haber cambiado) y el catálogo.
    authApi.me().then((usuario) => setSession((s) => (s ? { ...s, usuario } : s))).catch(() => {})
    reloadCatalog().catch(() => {})
  }, [token, reloadCatalog])

  const context = useMemo(() => ({
    user: session?.usuario,
    isAdmin: session?.usuario?.rol === 'ADMIN',
    catalog,
    reloadCatalog,
    logout,
  }), [session, catalog, reloadCatalog, logout])

  if (!session) {
    return <ToastProvider><LoginPage onLogin={setSession} /></ToastProvider>
  }

  return (
    <AppContext.Provider value={context}>
      <ToastProvider>
        <div className="ed-app">
          <header className="ed-header">
            <div className="ed-container ed-header__bar">
              <button type="button" className="ed-header__brand" onClick={() => setPage('leads')}>
                <img src={isotipo} alt="Estación Diseño" width="49" height="36" />
                <span className="ed-header__sep" />
                <span className="ed-display ed-header__title">Leads</span>
              </button>
              <nav className="ed-header__nav" aria-label="Secciones">
                {PAGES.filter((p) => !p.admin || session.usuario.rol === 'ADMIN').map((p) => (
                  <button key={p.id} type="button" className="ed-btn ed-btn--sm" data-on={page === p.id} onClick={() => setPage(p.id)}
                    aria-current={page === p.id ? 'page' : undefined}>
                    <Icon name={p.icon} size={14} />
                    <span className="ed-hide-sm">{p.label}</span>
                  </button>
                ))}
                <span className="ed-tag ed-tag--acc ed-hide-sm" title={session.usuario.email}>{session.usuario.nombre.split(' ')[0]}</span>
                <button type="button" className="ed-icon-btn" onClick={logout} title="Cerrar sesión" aria-label="Cerrar sesión">
                  <Icon name="logout" />
                </button>
              </nav>
            </div>
          </header>

          <main className="ed-container ed-main">
            {!catalog ? <Loading /> : (
              // key: al cambiar de sección se sale del aviso de error.
              <ErrorBoundary key={page}>
                {page === 'leads' && <LeadsPage openLeadId={leadToOpen} onLeadOpened={() => setLeadToOpen(null)} />}
                {page === 'importar' && <ImportPage onDone={() => setPage('leads')} />}
                {page === 'ajustes' && <SettingsPage />}
                {page === 'actividad' && session.usuario.rol === 'ADMIN' && <ActivityPage />}
              </ErrorBoundary>
            )}
          </main>
          <CallReminders onOpen={openLead} />
        </div>
      </ToastProvider>
    </AppContext.Provider>
  )
}
