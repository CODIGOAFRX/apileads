import { useEffect, useState } from 'react'
import { leadsApi, mailApi } from '../../api/endpoints.js'
import { Icon } from '../../components/Icon.jsx'
import { Modal } from '../../components/Modal.jsx'
import { useToast } from '../../components/Toasts.jsx'
import { Alert, Loading, Spinner } from '../../components/ui.jsx'
import { EditableHtml, HtmlPreview } from '../../components/HtmlPreview.jsx'
import { formatBytes, formatDateTime, formatRelative, plural } from '../../lib/format.js'
import { displayName } from '../../lib/leads.js'

/**
 * Paso siguiente tras una acción (ver followUpAfter): «mail», «whatsapp» o
 * «mail-whatsapp» (tras no contesta: correo y, al aceptarlo, WhatsApp).
 * onProgress: el lead ha cambiado pero la ventana sigue abierta (p. ej. tras
 * mandar el correo de un estudio, queda el del otro).
 */
export function FollowUp({ type, lead, section, onClose, onSent, onProgress }) {
  if (type === 'whatsapp') return <WhatsappModal lead={lead} section={section} onClose={onClose} />
  return <MailModal lead={lead} section={section} andWhatsapp={type === 'mail-whatsapp'} onClose={onClose} onSent={onSent} onProgress={onProgress} />
}

function openWhatsapp(lead) {
  window.open(lead.whatsappUrl, '_blank', 'noopener,noreferrer')
  leadsApi.whatsapp(lead.id).catch(() => {})
}

/** Cuenta atrás en segundos hasta 0 (para el turno de envío de correos). */
function useCountdown() {
  const [seconds, setSeconds] = useState(0)
  useEffect(() => {
    if (seconds <= 0) return undefined
    const timer = setTimeout(() => setSeconds((s) => Math.max(0, s - 1)), 1000)
    return () => clearTimeout(timer)
  }, [seconds])
  return [seconds, setSeconds]
}

/**
 * Vista previa y envío del correo de información. Un correo por estudio: si el
 * lead tiene varios, la comercial elige de cuál le escribe y, al enviarlo, la
 * ventana pasa al siguiente que aún no ha recibido (en otro correo, con su dossier).
 * Con «andWhatsapp», al aceptar se abre también WhatsApp. Se abre en el mismo
 * clic (antes de esperar al envío) para que el navegador no lo bloquee.
 * Toda la aplicación envía como mucho un correo cada 72 s: si no toca, el
 * botón muestra cuánto falta (no se encola nada).
 */
export function MailModal({ lead, section, onClose, onSent, onProgress, andWhatsapp = false }) {
  const toast = useToast()
  const [preview, setPreview] = useState(null)
  // Estudio elegido (null = el que proponga el servidor) y sus estudios, que no
  // desaparecen mientras se carga el correo de otro.
  const [studyId, setStudyId] = useState(null)
  const [studies, setStudies] = useState([])
  // Estudios mandados desde esta ventana (también los simulados, que no constan como enviados).
  const [sentIds, setSentIds] = useState([])
  const sentCount = sentIds.length
  const [error, setError] = useState('')
  const [sending, setSending] = useState(false)
  const [whatsappOpened, setWhatsappOpened] = useState(false)
  // Retoques para este correo concreto (null = tal cual sale de la plantilla).
  const [subject, setSubject] = useState(null)
  const [html, setHtml] = useState(null)
  const [version, setVersion] = useState(0)
  const [wait, setWait] = useCountdown()
  const edited = (subject !== null && subject !== preview?.asunto) || html !== null
  const several = studies.length > 1
  const current = studies.find((s) => s.id === preview?.estudioId)

  useEffect(() => {
    let alive = true
    leadsApi.mailPreview(lead.id, studyId)
      .then((data) => {
        if (!alive) return
        setPreview(data)
        setStudies(data.estudios || [])
        setWait((w) => Math.max(w, data.esperaSegundos || 0))
      })
      .catch((err) => alive && setError(err.message))
    return () => { alive = false }
  }, [lead.id, studyId, setWait])

  const resetChanges = () => {
    setSubject(null)
    setHtml(null)
    setVersion((v) => v + 1)
  }

  const chooseStudy = (id) => {
    if (sending || !preview || id === preview.estudioId) return
    if (edited && !window.confirm('Has retocado este correo. Si cambias de estudio se pierden los cambios. ¿Seguir?')) return
    setPreview(null)
    setError('')
    resetChanges()
    setStudyId(id)
  }

  const send = async () => {
    if (wait > 0 || !preview) return
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
        preview.estudioId ?? undefined,
      )
      const what = current ? `la información de ${current.nombre}` : 'la información'
      if (updated.envio?.simulado) {
        toast.error('Modo pruebas: el correo NO ha salido (MAIL_TRANSPORT=log). Configura tu buzón en Ajustes → Usuarios.')
      } else {
        toast.ok(`Enviada ${what} a ${preview.para}`)
      }
      if (updated.envio?.aviso) toast.info(updated.envio.aviso)

      // ¿Le queda algún estudio sin recibir? Se queda abierta con el siguiente.
      const done = [...sentIds, preview.estudioId]
      const next = studies.find((s) => !done.includes(s.id) && !s.ultimoEnvio)
      if (next) {
        onProgress?.(updated)
        setSentIds(done)
        setSending(false)
        setWait((w) => Math.max(w, preview.intervaloSegundos || 72))
        setPreview(null)
        resetChanges()
        setStudyId(next.id)
        return
      }
      onSent(updated)
    } catch (err) {
      // Otra comercial acaba de enviar uno: se muestra la cuenta atrás, no un error.
      if (err.status === 429) setWait(err.details?.esperaSegundos || preview.intervaloSegundos || 72)
      else setError(err.message)
      setSending(false)
    }
  }

  const onlyWhatsapp = () => {
    openWhatsapp(lead)
    onClose()
  }

  const sendLabel = several && current ? `Enviar ${current.nombre}` : 'Enviar correo'
  const sendButton = (label, autofocus) => (
    <button type="button" className="ed-btn ed-btn--solid" onClick={send} disabled={!preview || sending || wait > 0} data-autofocus={autofocus || undefined}>
      {sending ? <Spinner /> : <Icon name={wait > 0 ? 'clock' : 'send'} size={14} />} {wait > 0 ? `Enviar en ${wait} s` : label}
    </button>
  )

  const footer = !andWhatsapp || sentCount > 0 ? (
    <>
      <button type="button" className="ed-btn" onClick={onClose}>{sentCount > 0 ? 'Terminar' : 'Ahora no'}</button>
      {sendButton(sendLabel, true)}
    </>
  ) : whatsappOpened ? (
    <>
      <button type="button" className="ed-btn" onClick={onClose}>Cerrar</button>
      {sendButton('Reintentar envío')}
    </>
  ) : (
    <>
      <button type="button" className="ed-btn" onClick={onlyWhatsapp}><Icon name="whatsapp" size={14} /> Solo WhatsApp</button>
      {sendButton(several && current ? `Enviar ${current.nombre} y abrir WhatsApp` : 'Enviar y abrir WhatsApp', true)}
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
      {several && (
        <div className="ed-mailstudy">
          <div className="ed-mailstudy__head">
            <span className="ed-label">¿De qué estudio le escribes?</span>
            <span className="ed-hint">Le interesan {studies.length}: cada uno va en su propio correo, con su dossier.</span>
          </div>
          <div className="ed-studypick ed-mailstudy__pick" role="radiogroup" aria-label="Estudio del correo">
            {studies.map((study) => {
              const selected = study.id === (preview?.estudioId ?? studyId)
              return (
                <button key={study.id} type="button" role="radio" aria-checked={selected} aria-pressed={selected}
                  style={{ '--sc': study.color || undefined }} disabled={sending} onClick={() => chooseStudy(study.id)}>
                  <span className="ed-chip__dot" style={{ background: study.color || undefined }} />
                  {study.nombre}
                  {study.ultimoEnvio && (
                    <span className="ed-mailstudy__sent" title={`Enviado el ${formatDateTime(study.ultimoEnvio)}`}>
                      <Icon name="check" size={11} strokeWidth={3} /> enviado {formatRelative(study.ultimoEnvio)}
                    </span>
                  )}
                </button>
              )
            })}
          </div>
        </div>
      )}
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
              ) : <span className="ed-muted">Sin dossier{current ? ` (${current.nombre} no tiene dossier propio)` : ''}</span>}
            </dd>
          </dl>
          {preview.simulado && (
            <Alert tone="error">
              Modo pruebas: este correo <strong>no saldrá de verdad</strong> (no tienes buzón propio y el servidor tiene MAIL_TRANSPORT=log).
              Pide a administración que configure tu buzón en Ajustes → Usuarios.
            </Alert>
          )}
          {wait > 0 && (
            <Alert tone="info">
              Para que no nos marquen como spam, la aplicación envía como mucho un correo cada {preview.intervaloSegundos} segundos. Podrás enviar este en {wait} s.
            </Alert>
          )}
          {preview.ultimoEnvioEstudio
            ? <Alert>Ya se le envió la información de {current?.nombre ?? 'este estudio'} el {formatDateTime(preview.ultimoEnvioEstudio)}.</Alert>
            : preview.ultimoEnvio && !several && <Alert>Ya se le envió información el {formatDateTime(preview.ultimoEnvio)}.</Alert>}
          <div className="ed-row ed-row--between">
            <span className="ed-hint">Puedes escribir en el correo (p. ej. «la matrícula cierra el viernes»): el cambio solo vale para este envío.</span>
            {edited && (
              <button type="button" className="ed-btn ed-btn--sm ed-btn--ghost" onClick={resetChanges}>
                <Icon name="reopen" size={13} /> Deshacer cambios
              </button>
            )}
          </div>
          <EditableHtml key={version} html={preview.html} onChange={setHtml} />
          {preview.firma
            ? <HtmlPreview html={preview.firma} title="Tu firma (se añade al final)" compact />
            : <p className="ed-hint" style={{ margin: 0 }}>Sin firma: administración la añade en Ajustes → Usuarios.</p>}
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
