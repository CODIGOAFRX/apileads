import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { leadsApi } from '../../api/endpoints.js'
import { Icon } from '../../components/Icon.jsx'
import { useToast } from '../../components/Toasts.jsx'
import { Alert, Spinner } from '../../components/ui.jsx'
import { dayKey, formatRelative } from '../../lib/format.js'
import { CALL_OUTCOMES, displayName, followUpAfter } from '../../lib/leads.js'

// Si el menú se cierra y se vuelve a abrir enseguida (o React lo monta dos veces en
// desarrollo), la reserva no se suelta para volver a pedirla: se cancela la liberación.
const pendingUnlocks = new Map()

function reserve(id) {
  clearTimeout(pendingUnlocks.get(id))
  pendingUnlocks.delete(id)
  return leadsApi.lock(id)
}

function release(id) {
  clearTimeout(pendingUnlocks.get(id))
  pendingUnlocks.set(id, setTimeout(() => {
    pendingUnlocks.delete(id)
    leadsApi.unlock(id).catch(() => {})
  }, 400))
}

/**
 * Menú de llamada. Al abrirse reserva el lead para que nadie más lo llame a la
 * vez; al elegir un resultado lo registra y libera la reserva. WhatsApp y correo
 * están a mano desde el primer intento, conteste o no.
 */
export function CallMenu({ lead, anchor, onClose, onDone, onMail }) {
  const toast = useToast()
  const panel = useRef(null)
  const [position, setPosition] = useState({ top: 0, left: 0 })
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(null)
  const [lockError, setLockError] = useState('')
  const [scheduling, setScheduling] = useState(false)
  const [when, setWhen] = useState({ date: dayKey(new Date()), time: '' })
  const finished = useRef(false)
  const can = (action) => lead.acciones.includes(action)

  useLayoutEffect(() => {
    if (!anchor || !panel.current) return
    const rect = anchor.getBoundingClientRect()
    const { offsetWidth: w, offsetHeight: h } = panel.current
    const left = Math.max(12, Math.min(rect.right - w, window.innerWidth - w - 12))
    const below = rect.bottom + 8
    const top = below + h > window.innerHeight - 12 ? Math.max(12, rect.top - h - 8) : below
    setPosition({ top, left })
  }, [anchor, lockError, scheduling])

  useEffect(() => {
    reserve(lead.id).catch((err) => setLockError(err.message))
    return () => {
      if (!finished.current) release(lead.id)
    }
  }, [lead.id])

  useEffect(() => {
    const onKey = (event) => {
      if (event.key === 'Escape') {
        if (scheduling) setScheduling(false)
        else onClose()
        return
      }
      if (['TEXTAREA', 'INPUT'].includes(event.target.tagName)) return
      const outcome = CALL_OUTCOMES[Number(event.key) - 1]
      if (outcome && !lockError && !scheduling) {
        // Que la tecla no acabe escrita en el campo de hora que aparece con «4».
        event.preventDefault()
        choose(outcome.id)
      }
    }
    const onClick = (event) => {
      if (!panel.current?.contains(event.target) && !anchor?.contains(event.target)) onClose()
    }
    document.addEventListener('keydown', onKey)
    document.addEventListener('mousedown', onClick)
    return () => {
      document.removeEventListener('keydown', onKey)
      document.removeEventListener('mousedown', onClick)
    }
  })

  const choose = (outcome) => (outcome === 'AGENDAR' ? setScheduling(true) : register(outcome))

  const register = async (outcome) => {
    if (busy) return
    setBusy(outcome)
    try {
      const updated = await leadsApi.call(lead.id, outcome, note)
      finished.current = true
      toast.ok(messageFor(outcome, updated))
      onDone(updated, followUpAfter(outcome, updated))
    } catch (err) {
      toast.error(err)
      setBusy(null)
    }
  }

  const schedule = async (event) => {
    event.preventDefault()
    if (busy || !when.date || !when.time) return
    setBusy('AGENDAR')
    try {
      const updated = await leadsApi.scheduleCall(lead.id, `${when.date}T${when.time}`, note)
      finished.current = true
      toast.ok(`Llamada agendada ${formatRelative(updated.llamadaAgendada)}: ese día sale el primero`)
      onDone(updated)
    } catch (err) {
      toast.error(err)
      setBusy(null)
    }
  }

  return createPortal(
    <div ref={panel} className="ed-popover ed-stack" role="dialog" aria-label={`Llamada a ${displayName(lead)}`}
      style={{ top: position.top, left: position.left }} data-section="pendientes">
      <div>
        <p className="ed-label">Llamada · intento {lead.intentos + 1}</p>
        <p className="ed-strong" style={{ fontSize: 18, margin: '2px 0 0' }}>{displayName(lead)}</p>
        <div className="ed-row" style={{ gap: 6, marginTop: 8 }}>
          <a href={lead.telUrl} className="ed-btn ed-btn--sm ed-btn--acc">
            <Icon name="phone" size={13} /> {lead.telefono}
          </a>
          {can('whatsapp') && (
            <a className="ed-btn ed-btn--sm ed-btn--wa" href={lead.whatsappUrl} target="_blank" rel="noopener noreferrer"
              onClick={() => leadsApi.whatsapp(lead.id).catch(() => {})}>
              <Icon name="whatsapp" size={13} /> WhatsApp
            </a>
          )}
          {can('enviar-info') && (
            <button type="button" className="ed-btn ed-btn--sm ed-btn--mail" onClick={onMail}>
              <Icon name="send" size={13} /> Correo
            </button>
          )}
        </div>
      </div>

      {lockError ? (
        <Alert>{lockError}. Espera a que termine o elige otro lead.</Alert>
      ) : (
        <>
          <textarea className="ed-textarea" style={{ minHeight: 60 }} placeholder="Nota (opcional): qué te ha dicho, cuándo prefiere…"
            value={note} onChange={(e) => setNote(e.target.value)} />
          {scheduling ? (
            <form className="ed-stack ed-stack--sm ed-schedule" onSubmit={schedule}>
              <p className="ed-label" style={{ margin: 0 }}>¿Cuándo le vuelves a llamar? (hora de Madrid)</p>
              <div className="ed-row" style={{ gap: 8, flexWrap: 'nowrap' }}>
                <input className="ed-input" type="date" aria-label="Día" required min={dayKey(new Date())}
                  value={when.date} onChange={(e) => setWhen({ ...when, date: e.target.value })} />
                <input className="ed-input" type="time" aria-label="Hora" required autoFocus style={{ maxWidth: 120 }}
                  value={when.time} onChange={(e) => setWhen({ ...when, time: e.target.value })} />
              </div>
              <div className="ed-row ed-row--end" style={{ gap: 6 }}>
                <button type="button" className="ed-btn ed-btn--sm" onClick={() => setScheduling(false)}>Volver</button>
                <button type="submit" className="ed-btn ed-btn--sm ed-btn--solid" disabled={Boolean(busy) || !when.date || !when.time}>
                  {busy === 'AGENDAR' ? <Spinner /> : <Icon name="calendar" size={13} />} Agendar llamada
                </button>
              </div>
            </form>
          ) : (
            <div className="ed-stack ed-stack--sm">
              {CALL_OUTCOMES.map((outcome, index) => (
                <button key={outcome.id} type="button" className="ed-outcome" data-tone={outcome.tone}
                  onClick={() => choose(outcome.id)} disabled={Boolean(busy)}>
                  {busy === outcome.id ? <Spinner /> : <Icon name={outcome.icon} size={18} />}
                  <span>
                    <span className="ed-outcome__title">{outcome.label}</span><br />
                    <span className="ed-outcome__hint">{outcome.hint}</span>
                  </span>
                  <kbd>{index + 1}</kbd>
                </button>
              ))}
            </div>
          )}
        </>
      )}
    </div>,
    document.body,
  )
}

function messageFor(outcome, lead) {
  if (outcome === 'NO_INTERESADO') return `${displayName(lead)} pasa a descartados`
  if (outcome === 'INTERESADO') return `${displayName(lead)} pasa a interesados`
  if (lead.estado === 'DESCARTADO') return `Sin respuesta tras ${lead.intentos} intentos: pasa a descartados`
  if (lead.estado !== 'NO_CONTESTA') return `Intento ${lead.intentos} anotado`
  return `No contesta (intento ${lead.intentos}). Vuelve ${formatRelative(lead.proximaLlamada)}`
}
