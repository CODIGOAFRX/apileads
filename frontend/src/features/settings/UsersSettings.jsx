import { useEffect, useState } from 'react'
import { usersApi } from '../../api/endpoints.js'
import { useApp } from '../../AppContext.js'
import { Icon } from '../../components/Icon.jsx'
import { Modal } from '../../components/Modal.jsx'
import { useToast } from '../../components/Toasts.jsx'
import { Alert, Checkbox, Field, Loading, Spinner } from '../../components/ui.jsx'
import { formatRelative } from '../../lib/format.js'
import { MailAccountModal } from './MailAccountModal.jsx'

export function UsersSettings() {
  const { user: me } = useApp()
  const [users, setUsers] = useState(null)
  const [editing, setEditing] = useState(null)
  const [mailbox, setMailbox] = useState(null)
  const load = () => usersApi.list().then(setUsers)
  useEffect(() => { load() }, [])

  if (!users) return <Loading />

  return (
    <div className="ed-stack">
      <div className="ed-row ed-row--between">
        <p className="ed-muted" style={{ margin: 0 }}>Las comerciales trabajan los leads; administración además gestiona usuarios, estudios y correos. Con <Icon name="mail" size={13} /> eliges desde qué buzón envía cada una.</p>
        <button type="button" className="ed-btn ed-btn--solid ed-btn--sm" onClick={() => setEditing({})}><Icon name="plus" size={14} /> Nuevo usuario</button>
      </div>
      <div className="ed-table-wrap">
        <table className="ed-table">
          <thead><tr><th>Nombre</th><th>Correo</th><th>Envía desde</th><th>Rol</th><th>Estado</th><th>Último acceso</th><th /></tr></thead>
          <tbody>
            {users.map((u) => (
              <tr key={u.id}>
                <td className="ed-medium">{u.nombre}{u.id === me.id && <span className="ed-muted"> (tú)</span>}</td>
                <td>{u.email}</td>
                <td>
                  {u.correoEnvio
                    ? <span className="ed-tag ed-tag--ok" style={{ textTransform: 'none', letterSpacing: 0 }}>{u.correoEnvio}</span>
                    : <span className="ed-tag ed-tag--ghost" title="Sus correos salen del buzón general (MAIL_FROM)">Buzón general</span>}
                </td>
                <td><span className={`ed-tag ${u.rol === 'ADMIN' ? 'ed-tag--solid' : ''}`}>{u.rol === 'ADMIN' ? 'Admin' : 'Comercial'}</span></td>
                <td>{u.activo ? <span className="ed-tag ed-tag--ok">Activo</span> : <span className="ed-tag ed-tag--ghost">Desactivado</span>}</td>
                <td>{u.ultimoAcceso ? formatRelative(u.ultimoAcceso) : 'Nunca'}</td>
                <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                  <button type="button" className="ed-icon-btn ed-icon-btn--sm" onClick={() => setMailbox(u)} aria-label={`Correo de envío de ${u.nombre}`} title="Correo de envío"><Icon name="mail" size={14} /></button>
                  {' '}
                  <button type="button" className="ed-icon-btn ed-icon-btn--sm" onClick={() => setEditing(u)} aria-label={`Editar ${u.nombre}`} title="Editar usuario"><Icon name="edit" size={14} /></button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {editing && <UserModal user={editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); load() }} />}
      {mailbox && <MailAccountModal user={mailbox} onClose={() => setMailbox(null)} onSaved={() => { setMailbox(null); load() }} />}
    </div>
  )
}

function UserModal({ user, onClose, onSaved }) {
  const toast = useToast()
  const isNew = !user.id
  const [form, setForm] = useState({ nombre: user.nombre || '', email: user.email || '', rol: user.rol || 'COMERCIAL', activo: user.activo ?? true, password: '' })
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const set = (key) => (e) => setForm({ ...form, [key]: e?.target ? e.target.value : e })

  const save = async (event) => {
    event?.preventDefault()
    setBusy(true)
    setError('')
    try {
      if (isNew) await usersApi.create(form)
      else await usersApi.update(user.id, form)
      toast.ok(isNew ? 'Usuario creado' : 'Usuario actualizado')
      onSaved()
    } catch (err) {
      setError(err.message)
      setBusy(false)
    }
  }

  return (
    <Modal title={isNew ? 'Nuevo usuario' : 'Editar usuario'} label={user.email} onClose={onClose} section="ajustes"
      footer={(
        <>
          <button type="button" className="ed-btn" onClick={onClose}>Cancelar</button>
          <button type="button" className="ed-btn ed-btn--solid" onClick={save} disabled={busy}>{busy && <Spinner />} Guardar</button>
        </>
      )}>
      <form className="ed-stack" onSubmit={save}>
        {error && <Alert tone="error">{error}</Alert>}
        <Field label="Nombre"><input className="ed-input" value={form.nombre} onChange={set('nombre')} required /></Field>
        <Field label="Correo (para entrar)"><input className="ed-input" type="email" value={form.email} onChange={set('email')} required /></Field>
        <Field label="Rol">
          <select className="ed-select" value={form.rol} onChange={set('rol')}>
            <option value="COMERCIAL">Comercial</option>
            <option value="ADMIN">Administración</option>
          </select>
        </Field>
        <Field label={isNew ? 'Contraseña' : 'Nueva contraseña'} hint={isNew ? 'Mínimo 10 caracteres' : 'Déjala vacía para no cambiarla'}>
          <input className="ed-input" type="password" autoComplete="new-password" value={form.password} onChange={set('password')} required={isNew} />
        </Field>
        {!isNew && <Checkbox checked={form.activo} onChange={set('activo')}>Cuenta activa (si la desactivas, no podrá entrar)</Checkbox>}
        <button type="submit" hidden />
      </form>
    </Modal>
  )
}
