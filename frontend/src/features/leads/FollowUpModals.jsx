import { useEffect, useState } from 'react'
import { leadsApi, mailApi } from '../../api/endpoints.js'
import { Icon } from '../../components/Icon.jsx'
import { Modal } from '../../components/Modal.jsx'
import { useToast } from '../../components/Toasts.jsx'
import { Alert, Loading, Spinner } from '../../components/ui.jsx'
import { EditableHtml } from '../../components/HtmlPreview.jsx'
import { formatBytes, formatDateTime, plural } from '../../lib/format.js'
import { displayName } from '../../lib/leads.js'

/**
 * Paso siguiente tras una acción (ver followUpAfter): «mail», «whatsapp» o
 * «mail-whatsapp» (tras no contesta: correo y, al aceptarlo, WhatsApp).
 */
export function FollowUp({ type, lead, section, onClose, onSent }) {
  if (type === 'whatsapp') return <WhatsappModal lead={lead} section={section} onClose={onClose} />
  return <MailModal lead={lead} section={section} andWhatsapp={type === 'mail-whatsapp'} onClose={onClose} onSent={onSent} />
}

function openWhatsapp(lead) {
  window.open(lead.whatsappUrl, '_blank', 'noopener,noreferrer')
  leadsApi.whatsapp(lead.id).catch(() => {})
}

/**
 * Vista previa y envío del correo de información según los estudios del lead.
 * Con «andWhatsapp», al aceptar se abre también WhatsApp. Se abre en el mismo
 * clic (antes de esperar al envío) para que el navegador no lo bloquee.
 */
export function MailModal({ lead, section, onClose, onSent, andWhatsapp = false }) {
  const toast = useToast()
  const [preview, setPreview] = useState(null)
  const [error, setError] = useState('')
  const [sending, setSending] = useState(false)
  const [whatsappOpened, setWhatsappOpened] = useState(false)
  // Retoques para este correo concreto (null = tal cual sale de la plantilla).
  const [subject, setSubject] = useState(null)
  const [html, setHtml] = useState(null)
  const [version, setVersion] = useState(0)
  const edited = (subject !== null && subject !== preview?.asunto) || html !== null

  useEffect(() => {
    leadsApi.mailPreview(lead.id).then(setPreview).catch((err) => setError(err.message))
  }, [lead.id])

  const undoChanges = () => {
    setSubject(null)
    setHtml(null)
    setVersion((v) => v + 1)
  }

  const send = async () => {
    if (andWhatsapp && !whatsappOpened) {
      openWhatsapp(lead)
      setWhatsappOpened(true)
    }
    setSending(true)
    setError('')
    try {
      const updated = await leadsApi.sendMail(
        lead.id,
        subject !== null && subject !== preview.asunto ? subject : undefined,
        html ?? undefined,
      )
      toast.ok(`Información enviada a ${preview.para}`)
      onSent(updated)
    } catch (err) {
      setError(err.message)
      setSending(false)
    }
  }

  const onlyWhatsapp = () => {
    openWhatsapp(lead)
    onClose()
  }

  const footer = !andWhatsapp ? (
    <>
      <button type="button" className="ed-btn" onClick={onClose}>Ahora no</button>
      <button type="button" className="ed-btn ed-btn--solid" onClick={send} disabled={!preview || sending} data-autofocus>
        {sending ? <Spinner /> : <Icon name="send" size={14} />} Enviar correo
      </button>
    </>
  ) : whatsappOpened ? (
    <>
      <button type="button" className="ed-btn" onClick={onClose}>Cerrar</button>
      <button type="button" className="ed-btn ed-btn--solid" onClick={send} disabled={!preview || sending}>
        {sending ? <Spinner /> : <Icon name="send" size={14} />} Reintentar envío
      </button>
    </>
  ) : (
    <>
      <button type="button" className="ed-btn" onClick={onlyWhatsapp}><Icon name="whatsapp" size={14} /> Solo WhatsApp</button>
      <button type="button" className="ed-btn ed-btn--solid" onClick={send} disabled={!preview || sending} data-autofocus>
        {sending ? <Spinner /> : <Icon name="send" size={14} />} Enviar y abrir WhatsApp
      </button>
    </>
  )

  return (
    <Modal
      title={andWhatsapp ? '¿Le envías este correo?' : 'Enviar información'}
      label={andWhatsapp ? `${displayName(lead)} · no contesta` : displayName(lead)}
      onClose={onClose}
      section={section}
      wide
      footer={footer}
    >
      {error && <Alert tone="error">{error}</Alert>}
      {!preview && !error && <Loading>Preparando el correo…</Loading>}
      {preview && (
        <div className="ed-stack">
          {andWhatsapp && !whatsappOpened && (
            <p style={{ margin: 0 }}>Al enviarlo se abrirá WhatsApp con un mensaje ya escrito para {lead.telefono}.</p>
          )}
          <dl className="ed-kv" style={{ alignItems: 'center' }}>
            <dt>De</dt><dd>{preview.de ?? <span className="ed-muted">Buzón general de la escuela</span>}</dd>
            <dt>Para</dt><dd>{preview.para}</dd>
            <dt>Asunto</dt>
            <dd><input className="ed-input" aria-label="Asunto" value={subject ?? preview.asunto} onChange={(e) => setSubject(e.target.value)} /></dd>
            <dt>Adjuntos</dt>
            <dd>
              {preview.adjuntos.length ? (
                <span className="ed-row" style={{ gap: 6 }}>
                  {preview.adjuntos.map((a) => (
                    <button key={a.id} type="button" className="ed-btn ed-btn--sm ed-btn--ghost" style={{ textTransform: 'none', letterSpacing: 0 }}
                      onClick={() => mailApi.download(a).catch(toast.error)} title="Ver el dossier">
                      <Icon name="file" size={13} /> {a.nombre} <span className="ed-muted">· {formatBytes(a.tamano)}</span>
                    </button>
                  ))}
                </span>
              ) : <span className="ed-muted">Sin dossier</span>}
            </dd>
          </dl>
          {preview.ultimoEnvio && <Alert>Ya se le envió información el {formatDateTime(preview.ultimoEnvio)}.</Alert>}
          <div className="ed-row ed-row--between">
            <span className="ed-hint">Puedes escribir en el correo (p. ej. «la matrícula cierra el viernes»): el cambio solo vale para este envío.</span>
            {edited && (
              <button type="button" className="ed-btn ed-btn--sm ed-btn--ghost" onClick={undoChanges}>
                <Icon name="reopen" size={13} /> Deshacer cambios
              </button>
            )}
          </div>
          <EditableHtml key={version} html={preview.html} onChange={setHtml} />
          {!(lead.estudios || []).length && (
            <Alert>No sabemos qué quiere estudiar: se enviará el correo genérico. Puedes asignarle un estudio desde su ficha.</Alert>
          )}
        </div>
      )}
    </Modal>
  )
}

/** Cuando no hay correo al que escribir, tras «no contesta» se ofrece WhatsApp. */
export function WhatsappModal({ lead, section, onClose }) {
  const open = () => {
    leadsApi.whatsapp(lead.id).catch(() => {})
    onClose()
  }
  return (
    <Modal
      title="¿Le escribes por WhatsApp?"
      label={`${displayName(lead)} · ${plural(lead.intentos, 'intento')} sin respuesta`}
      onClose={onClose}
      section={section}
      footer={(
        <>
          <button type="button" className="ed-btn" onClick={onClose}>Ahora no</button>
          <a className="ed-btn ed-btn--solid" href={lead.whatsappUrl} target="_blank" rel="noopener noreferrer" onClick={open} data-autofocus>
            <Icon name="whatsapp" size={14} /> Abrir WhatsApp
          </a>
        </>
      )}
    >
      <p style={{ marginTop: 0 }}>Se abrirá WhatsApp con un mensaje ya escrito que puedes cambiar antes de enviarlo. El botón sigue siempre disponible en la fila del lead.</p>
      <p className="ed-medium">{lead.telefono}</p>
    </Modal>
  )
}
