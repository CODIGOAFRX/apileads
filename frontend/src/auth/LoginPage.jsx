import { useState } from 'react'
import { authApi } from '../api/endpoints.js'
import { saveSession } from './session.js'
import { Icon } from '../components/Icon.jsx'
import { Alert, Checkbox, Spinner } from '../components/ui.jsx'
import logo from '../assets/logo-estacion-diseno.png'

export function LoginPage({ onLogin }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(true)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const submit = async (event) => {
    event.preventDefault()
    setBusy(true)
    setError('')
    try {
      const result = await authApi.login(email, password)
      onLogin(saveSession(result, remember))
    } catch (err) {
      setError(err.message)
      setBusy(false)
    }
  }

  return (
    <div className="ed-login" data-section="pendientes">
      <aside className="ed-login__brand">
        <div className="ed-line-v" aria-hidden="true">
          <span className="ed-line-v__rail" />
          {[['8%'], ['30%', 'var(--green)'], ['52%', 'var(--cyan)'], ['74%', 'var(--yellow)']].map(([top, bg]) => (
            <span key={top} className="ed-line-v__node" style={{ top, background: bg }} />
          ))}
        </div>
        <img src={logo} alt="Estación Diseño – Escuela Superior de Diseño" />
        <div>
          <h1 className="ed-display ed-login__title">Leads</h1>
          <p className="ed-login__lead">
            Todas las solicitudes de información en un mismo sitio: llama, apunta y haz seguimiento sin perder a nadie.
          </p>
        </div>
        <div className="ed-swatches" aria-hidden="true">
          {['--green', '--pink', '--coral', '--peri', '--yellow', '--cyan', '--purple', '--orange'].map((c) => (
            <span key={c} style={{ background: `var(${c})` }} />
          ))}
        </div>
      </aside>

      <main className="ed-login__form">
        <div>
          <img src={logo} alt="Estación Diseño" style={{ height: 40, marginBottom: 32 }} className="ed-login__mobile-logo" />
          <p className="ed-label">Equipo comercial</p>
          <h2 className="ed-display" style={{ fontSize: 56, margin: '4px 0 32px' }}>Entrar</h2>

          <form onSubmit={submit} className="ed-stack">
            {error && <Alert tone="error">{error}</Alert>}
            <div className="ed-field">
              <label className="ed-label" htmlFor="login-email">Correo</label>
              <div className="ed-input-wrap">
                <Icon name="user" />
                <input id="login-email" className="ed-input ed-input--icon" type="email" autoComplete="username" required
                  placeholder="tu@estaciondiseno.es" value={email} onChange={(e) => setEmail(e.target.value)} />
              </div>
            </div>
            <div className="ed-field">
              <label className="ed-label" htmlFor="login-password">Contraseña</label>
              <div className="ed-input-wrap">
                <Icon name="lock" />
                <input id="login-password" className="ed-input ed-input--icon" type="password" autoComplete="current-password" required
                  placeholder="••••••••••" value={password} onChange={(e) => setPassword(e.target.value)} />
              </div>
            </div>
            <Checkbox checked={remember} onChange={setRemember}>Mantener la sesión en este ordenador</Checkbox>
            <button type="submit" className="ed-btn ed-btn--solid ed-btn--lg ed-btn--block" disabled={busy}>
              {busy ? <Spinner label="Entrando" /> : 'Entrar'}
            </button>
          </form>
          <p className="ed-hint" style={{ marginTop: 18 }}>¿Sin acceso o has olvidado la contraseña? Pídeselo a la persona que administra la herramienta.</p>
        </div>
      </main>
    </div>
  )
}
