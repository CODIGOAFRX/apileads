import { useState } from 'react'
import { usersApi } from '../../api/endpoints.js'
import { HtmlPreview } from '../../components/HtmlPreview.jsx'
import { Icon } from '../../components/Icon.jsx'
import { Modal } from '../../components/Modal.jsx'
import { useToast } from '../../components/Toasts.jsx'
import { Alert, Field, Spinner } from '../../components/ui.jsx'
import { estacionSignature } from '../../lib/signature.js'

/**
 * Firma que se añade al final de cada correo que envía el usuario. Se rellena con
 * la de Estación Diseño a partir de sus datos o se pega el HTML de otra (p. ej. la
 * de Grupo EFP).
 */
export function SignatureModal({ user, onClose, onSaved }) {
  const toast = useToast()
  const [data, setData] = useState({ nombre: user.nombre || '', cargo: '', email: user.correoEnvio || user.email || '', telefono: '' })
  const [html, setHtml] = useState(user.firma || '')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const set = (key) => (e) => setData({ ...data, [key]: e.target.value })

  const save = async () => {
    setBusy(true)
    setError('')
    try {
      await usersApi.update(user.id, { firma: html })
      toast.ok(html.trim() ? 'Firma guardada' : 'Firma quitada')
      onSaved()
    } catch (err) {
      setError(err.message)
      setBusy(false)
    }
  }

  return (
    <Modal title="Firma de correo" label={user.nombre} onClose={onClose} section="ajustes" wide
      footer={(
        <>
          {html && <button type="button" className="ed-btn ed-btn--danger" onClick={() => setHtml('')} style={{ marginRight: 'auto' }}><Icon name="trash" size={14} /> Quitar firma</button>}
          <button type="button" className="ed-btn" onClick={onClose}>Cancelar</button>
          <button type="button" className="ed-btn ed-btn--solid" onClick={save} disabled={busy}>{busy && <Spinner />} Guardar</button>
        </>
      )}>
      <div className="ed-stack">
        {error && <Alert tone="error">{error}</Alert>}
        <p className="ed-muted" style={{ margin: 0 }}>Va al final de cada correo que envía {user.nombre.split(' ')[0]}, debajo del texto, y no se puede retocar al enviar.</p>

        <div className="ed-panel ed-panel--bone ed-stack ed-stack--sm">
          <h3 className="ed-label">Firma de Estación Diseño</h3>
          <div className="ed-grid ed-grid--2">
            <Field label="Nombre"><input className="ed-input" value={data.nombre} onChange={set('nombre')} /></Field>
            <Field label="Cargo"><input className="ed-input" value={data.cargo} onChange={set('cargo')} placeholder="Atención al usuario" /></Field>
            <Field label="Correo"><input className="ed-input" type="email" value={data.email} onChange={set('email')} /></Field>
            <Field label="Teléfono"><input className="ed-input" type="tel" value={data.telefono} onChange={set('telefono')} placeholder="682 642 048" /></Field>
          </div>
          <div>
            <button type="button" className="ed-btn ed-btn--sm" onClick={() => setHtml(estacionSignature(data))} disabled={!data.nombre.trim()}>
              <Icon name="refresh" size={13} /> {html ? 'Rehacer la firma con estos datos' : 'Crear la firma con estos datos'}
            </button>
          </div>
        </div>

        {html ? <HtmlPreview html={html} title="Así se verá la firma" compact /> : <p className="ed-hint">Sin firma: sus correos terminan en el texto de la plantilla.</p>}

        <details>
          <summary className="ed-label" style={{ cursor: 'pointer' }}>Pegar o editar el HTML (por ejemplo, la firma de Grupo EFP)</summary>
          <textarea className="ed-textarea" style={{ minHeight: 160, fontFamily: 'monospace', fontSize: 12, marginTop: 8 }}
            aria-label="HTML de la firma" value={html} onChange={(e) => setHtml(e.target.value)} />
        </details>
      </div>
    </Modal>
  )
}
