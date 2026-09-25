import { useEffect, useState } from 'react'
import { usersApi } from '../../api/endpoints.js'
import { Icon } from '../../components/Icon.jsx'
import { Modal } from '../../components/Modal.jsx'
import { useToast } from '../../components/Toasts.jsx'
import { Alert, Checkbox, Field, Loading, Spinner } from '../../components/ui.jsx'
import { initialAccount, updateAccount } from '../../lib/mailAccount.js'

const SECURITY = [
  { value: 'ssl', label: 'SSL/TLS (465)' },
  { value: 'tls', label: 'STARTTLS (587)' },
  { value: 'none', label: 'Ninguna' },
]

/**
 * Buzón desde el que un usuario envía el correo de información. Los datos
 * están en Plesk → Correo → el buzón → «Configuración del cliente de correo»
 * → «Configuración manual».
 */
export function MailAccountModal({ user, onClose, onSaved }) {
  const toast = useToast()
  const [saved, setSaved] = useState(undefined)
  const [form, setForm] = useState(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState('')
  const [check, setCheck] = useState(null)

  useEffect(() => {
    usersApi.mailAccount(user.id)
      .then(({ cuenta }) => {
        setSaved(cuenta)
        setForm(initialAccount(cuenta, user.email))
      })
      .catch((err) => setError(err.message))
  }, [user.id, user.email])

  const set = (key) => (e) => {
    setForm(updateAccount(form, key, e?.target ? e.target.value : e))
    setCheck(null)
  }

  const run = async (kind, action) => {
    setBusy(kind)
    setError('')
    try {
      await action()
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy('')
    }
  }

  const save = (event) => {
    event?.preventDefault()
    run('save', async () => {
      await usersApi.saveMailAccount(user.id, form)
      toast.ok(`Buzón de ${user.nombre} guardado`)
      onSaved()
    })
  }

  const test = () => run('test', async () => setCheck(await usersApi.testMailAccount(user.id, form)))

  const remove = () => run('remove', async () => {
    await usersApi.removeMailAccount(user.id)
    toast.ok('Buzón quitado: enviará desde el buzón general')
    onSaved()
  })

  const footer = form && (
    <>
      {saved && <button type="button" className="ed-btn ed-btn--danger" onClick={remove} disabled={!!busy}><Icon name="trash" size={14} /> Quitar</button>}
      <span className="ed-grow" />
      <button type="button" className="ed-btn" onClick={test} disabled={!!busy}>{busy === 'test' ? <Spinner /> : <Icon name="refresh" size={14} />} Probar conexión</button>
      <button type="button" className="ed-btn ed-btn--solid" onClick={save} disabled={!!busy}>{busy === 'save' && <Spinner />} Guardar</button>
    </>
  )

  return (
    <Modal title="Correo de envío" label={user.nombre} onClose={onClose} section="ajustes" wide footer={footer}>
      {error && <Alert tone="error">{error}</Alert>}
      {saved === undefined && !error && <Loading />}
      {form && (
        <form className="ed-stack" onSubmit={save}>
          <p className="ed-muted" style={{ margin: 0 }}>
            Los correos de información que envíe {user.nombre} saldrán desde este buzón y las respuestas le llegarán a él.
            Los datos están en Plesk → Correo → el buzón → «Configuración del cliente de correo» → «Configuración manual».
          </p>

          <div className="ed-grid ed-grid--2">
            <Field label="Dirección de correo">
              <input className="ed-input" type="email" required value={form.email} onChange={set('email')} placeholder="nombre@estaciondiseno.es" />
            </Field>
            <Field label="Nombre de usuario del servidor" hint="Normalmente es la misma dirección">
              <input className="ed-input" value={form.usuario} onChange={set('usuario')} placeholder={form.email || 'nombre@estaciondiseno.es'} autoComplete="off" />
            </Field>
          </div>
          <Field label="Contraseña del buzón" hint={saved ? 'Déjala vacía para no cambiarla' : 'La del buzón de correo (no la de esta aplicación). Se guarda cifrada.'}>
            <input className="ed-input" type="password" autoComplete="new-password" value={form.password} onChange={set('password')} required={!saved} />
          </Field>

          <p className="ed-label" style={{ margin: '8px 0 0' }}>Servidor de correo saliente (SMTP)</p>
          <div className="ed-grid ed-grid--3">
            <Field label="Servidor"><input className="ed-input" required value={form.smtpHost} onChange={set('smtpHost')} placeholder="estaciondiseno.es" /></Field>
            <Field label="Puerto"><input className="ed-input" type="number" min="1" max="65535" required value={form.smtpPuerto} onChange={set('smtpPuerto')} /></Field>
            <Field label="Seguridad">
              <select className="ed-select" value={form.smtpCifrado} onChange={set('smtpCifrado')}>
                {SECURITY.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
              </select>
            </Field>
          </div>

          <p className="ed-label" style={{ margin: '8px 0 0' }}>Servidor de correo entrante (IMAP)</p>
          <Checkbox checked={form.guardarEnviados} onChange={set('guardarEnviados')}>
            Guardar una copia en su carpeta de Enviados (para verlo en Outlook, Gmail o el móvil)
          </Checkbox>
          <div className="ed-grid ed-grid--3">
            <Field label="Servidor"><input className="ed-input" value={form.imapHost} onChange={set('imapHost')} placeholder="estaciondiseno.es" required={form.guardarEnviados} /></Field>
            <Field label="Puerto IMAP"><input className="ed-input" type="number" min="1" max="65535" value={form.imapPuerto} onChange={set('imapPuerto')} /></Field>
            <Field label="Carpeta de Enviados" hint="Se busca sola; escríbela solo si no la encuentra">
              <input className="ed-input" value={form.carpetaEnviados} onChange={set('carpetaEnviados')} placeholder="automática" />
            </Field>
          </div>

          <Checkbox checked={form.verificarCertificado} onChange={set('verificarCertificado')}>
            Comprobar el certificado SSL del servidor (desmárcalo solo si la prueba da error de certificado «no de confianza»)
          </Checkbox>

          {check && (
            <div className="ed-stack ed-stack--sm">
              <Alert tone={check.smtp.ok ? 'ok' : 'error'}><strong>Envío (SMTP):</strong> {check.smtp.mensaje}</Alert>
              {check.imap && <Alert tone={check.imap.ok ? 'ok' : 'error'}><strong>Copia en Enviados (IMAP):</strong> {check.imap.mensaje}</Alert>}
            </div>
          )}
          <button type="submit" hidden />
        </form>
      )}
    </Modal>
  )
}
