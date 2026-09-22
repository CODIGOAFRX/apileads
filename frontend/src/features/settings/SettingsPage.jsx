import { useState } from 'react'
import { useApp } from '../../AppContext.js'
import { AccountSettings } from './AccountSettings.jsx'
import { UsersSettings } from './UsersSettings.jsx'
import { CatalogSettings } from './CatalogSettings.jsx'
import { MailSettings } from './MailSettings.jsx'

const SECTIONS = [
  { id: 'cuenta', label: 'Mi cuenta', admin: false, Component: AccountSettings },
  { id: 'usuarios', label: 'Usuarios', admin: true, Component: UsersSettings },
  { id: 'estudios', label: 'Estudios y centros', admin: true, Component: CatalogSettings },
  { id: 'correos', label: 'Correos', admin: true, Component: MailSettings },
]

export function SettingsPage() {
  const { isAdmin } = useApp()
  const available = SECTIONS.filter((s) => isAdmin || !s.admin)
  const [active, setActive] = useState(available[isAdmin ? 1 : 0].id)
  const { Component } = available.find((s) => s.id === active)

  return (
    <div data-section="ajustes">
      <div className="ed-track">
        <section className="ed-hero ed-stop">
          <div>
            <p className="ed-label ed-label--dark">{isAdmin ? 'Administración' : 'Tu cuenta'}</p>
            <h1 className="ed-display ed-hero__title">Ajustes</h1>
          </div>
        </section>
        {available.length > 1 && (
          <div className="ed-segment" role="tablist" style={{ marginBottom: 20 }}>
            {available.map((s) => (
              <button key={s.id} type="button" role="tab" aria-pressed={active === s.id} aria-selected={active === s.id} onClick={() => setActive(s.id)}>
                {s.label}
              </button>
            ))}
          </div>
        )}
        <Component />
      </div>
    </div>
  )
}
