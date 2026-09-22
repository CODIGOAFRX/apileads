import { useState } from 'react'
import { authApi } from '../../api/endpoints.js'
import { useApp } from '../../AppContext.js'
import { useToast } from '../../components/Toasts.jsx'
import { Alert, Field, Spinner } from '../../components/ui.jsx'

export function AccountSettings() {
  const { user } = useApp()
  const toast = useToast()
  const [form, setForm] = useState({ actual: '', nueva: '', repetir: '' })
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const set = (key) => (e) => setForm({ ...form, [key]: e.target.value })

  const submit = async (event) => {
    event.preventDefault()
    if (form.nueva !== form.repetir) {
      setError('Las contraseñas nuevas no coinciden')
      return
    }
    setBusy(true)
    setError('')
    try {
      await authApi.changePassword(form.actual, form.nueva)
      setForm({ actual: '', nueva: '', repetir: '' })
      toast.ok('Contraseña actualizada')
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="ed-grid ed-grid--2" style={{ alignItems: 'start' }}>
      <div className="ed-panel">
        <p className="ed-label">Sesión iniciada como</p>
        <h2 className="ed-display" style={{ fontSize: 32, margin: '4px 0' }}>{user.nombre}</h2>
        <p className="ed-muted" style={{ margin: 0 }}>{user.email} · {user.rol === 'ADMIN' ? 'Administración' : 'Comercial'}</p>
      </div>
      <form className="ed-panel ed-stack" onSubmit={submit}>
        <h2 className="ed-display" style={{ fontSize: 28 }}>Cambiar contraseña</h2>
        {error && <Alert tone="error">{error}</Alert>}
        <Field label="Contraseña actual"><input className="ed-input" type="password" autoComplete="current-password" value={form.actual} onChange={set('actual')} required /></Field>
        <Field label="Nueva contraseña" hint="Mínimo 10 caracteres"><input className="ed-input" type="password" autoComplete="new-password" minLength={10} value={form.nueva} onChange={set('nueva')} required /></Field>
        <Field label="Repite la nueva contraseña"><input className="ed-input" type="password" autoComplete="new-password" value={form.repetir} onChange={set('repetir')} required /></Field>
        <button type="submit" className="ed-btn ed-btn--solid" disabled={busy}>{busy && <Spinner />} Guardar</button>
      </form>
    </div>
  )
}
