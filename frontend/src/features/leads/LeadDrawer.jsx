import { useCallback, useEffect, useRef, useState } from 'react'
import { leadsApi } from '../../api/endpoints.js'
import { Icon } from '../../components/Icon.jsx'
import { Drawer, Modal } from '../../components/Modal.jsx'
import { useToast } from '../../components/Toasts.jsx'
import { Alert, Field, Loading, Spinner, StatusTag, StudyChip } from '../../components/ui.jsx'
import { formatDateTime, formatRelative, toLocalInput } from '../../lib/format.js'
import { displayName, followUpAfter } from '../../lib/leads.js'
import { CallMenu } from './CallMenu.jsx'
import { LeadFormModal } from './LeadFormModal.jsx'
import { FollowUp } from './FollowUpModals.jsx'

const FOLLOW_UPS = ['mail', 'whatsapp', 'mail-whatsapp']

/** Ficha completa del lead: datos, acciones del embudo, notas e historial. */
export function LeadDrawer({ id, section, onClose, onChanged }) {
  const toast = useToast()
  const [lead, setLead] = useState(null)
  const [error, setError] = useState('')
  const [dialog, setDialog] = useState(null)
  const [calling, setCalling] = useState(false)
  const [note, setNote] = useState('')
  const [savingNote, setSavingNote] = useState(false)
  const callButton = useRef(null)

  const load = useCallback(() => leadsApi.get(id).then(setLead).catch((err) => setError(err.message)), [id])
  // Al abrir la ficha se anota en el registro; las recargas tras una acción, no.
  useEffect(() => {
    setLead(null)
    leadsApi.get(id, true).then(setLead).catch((err) => setError(err.message))
  }, [id])

  const apply = (updated, message) => {
    setLead(updated)
    onChanged(updated)
    if (message) toast.ok(message)
    setDialog(null)
  }

  const run = async (request, message) => {
    try {
      apply(await request(), message)
    } catch (err) {
      toast.error(err)
    }
  }

  const markInterested = async () => {
    try {
      const updated = await leadsApi.interested(id)
      apply(updated, 'Marcado como interesado')
      const next = followUpAfter('INTERESADO', updated)
      if (next) setDialog(next)
    } catch (err) {
      toast.error(err)
    }
  }

  const addNote = async (event) => {
    event.preventDefault()
    if (!note.trim()) return
    setSavingNote(true)
    try {
      setLead(await leadsApi.note(id, note))
      setNote('')
    } catch (err) {
      toast.error(err)
    } finally {
      setSavingNote(false)
    }
  }

  if (!lead) {
    return (
      <Drawer onClose={onClose} section={section} header={<h2 className="ed-display ed-drawer__title">Ficha</h2>}>
        {error ? <Alert tone="error">{error}</Alert> : <Loading />}
      </Drawer>
    )
  }

  const can = (action) => lead.acciones.includes(action)

  return (
    <Drawer
      onClose={onClose}
      section={section}
      header={(
        <>
          <div className="ed-row" style={{ gap: 6 }}>
            <StatusTag lead={lead} />
            <span className="ed-tag">{lead.origenLabel}</span>
            {lead.intentos > 0 && <span className="ed-tag ed-tag--warn">{lead.intentos} sin respuesta</span>}
            {lead.bloqueo && !lead.bloqueo.esMio && <span className="ed-tag ed-tag--busy">{lead.bloqueo.usuario} está llamando</span>}
          </div>
          <h2 className="ed-display ed-drawer__title" style={{ marginTop: 8 }}>{displayName(lead)}</h2>
        </>
      )}
    >
      <div className="ed-row">
        {can('llamar') && (
          <button ref={callButton} type="button" className="ed-btn ed-btn--solid" onClick={() => setCalling(true)}>
            <Icon name="phone" size={14} /> Llamada
          </button>
        )}
        {can('whatsapp') && (
          <a className="ed-btn" href={lead.whatsappUrl} target="_blank" rel="noopener noreferrer"
            onClick={() => leadsApi.whatsapp(lead.id).then(load).catch(() => {})}>
            <Icon name="whatsapp" size={14} /> WhatsApp
          </a>
        )}
        {can('enviar-info') && (
          <button type="button" className="ed-btn" onClick={() => setDialog('mail')}><Icon name="send" size={14} /> Enviar información</button>
        )}
        {can('interesado') && (
          <button type="button" className="ed-btn ed-btn--ok" onClick={markInterested}><Icon name="check" size={14} strokeWidth={3} /> Interesado</button>
        )}
        {can('citar') && (
          <button type="button" className="ed-btn" onClick={() => setDialog('cita')}><Icon name="calendar" size={14} /> {lead.citaEn ? 'Cambiar cita' : 'Agendar cita'}</button>
        )}
        {can('matricular') && (
          <button type="button" className="ed-btn" onClick={() => setDialog('matricula')}><Icon name="award" size={14} /> Matriculado</button>
        )}
        {can('descartar') && (
          <button type="button" className="ed-btn ed-btn--danger" onClick={() => setDialog('descarte')}><Icon name="ban" size={14} /> Descartar</button>
        )}
        {can('reabrir') && (
          <button type="button" className="ed-btn ed-btn--acc" onClick={() => run(() => leadsApi.reopen(id), 'Lead reabierto')}><Icon name="reopen" size={14} /> Reabrir</button>
        )}
        <button type="button" className="ed-btn ed-btn--ghost" onClick={() => setDialog('editar')}><Icon name="edit" size={14} /> Editar</button>
      </div>

      <section className="ed-panel ed-panel--flat ed-drawer__section">
        <dl className="ed-kv">
          <dt>Teléfono</dt>
          <dd>{lead.telefono ? (lead.telUrl ? <a href={lead.telUrl} onClick={() => leadsApi.dial(lead.id).catch(() => {})}>{lead.telefono}</a> : <span className="ed-lead__invalid">{lead.telefono} (no reconocido)</span>) : '—'}</dd>
          <dt>Correo</dt>
          <dd>{lead.email ? <span className={lead.emailValido ? '' : 'ed-lead__invalid'}>{lead.email}{!lead.emailValido && ' (formato dudoso)'}</span> : '—'}</dd>
          <dt>Ubicación</dt><dd>{lead.ubicacion || '—'}</dd>
          <dt>Estudios</dt>
          <dd>
            {lead.estudios.length
              ? <div className="ed-row" style={{ gap: 4 }}>{lead.estudios.map((s) => <StudyChip key={s.id} study={s} />)}</div>
              : <span className="ed-muted">Sin asignar</span>}
            {lead.interes && <div className="ed-tiny ed-muted" style={{ marginTop: 4 }}>Escribió: «{lead.interes}»</div>}
          </dd>
          {lead.centro && <><dt>Centro</dt><dd>{lead.centro.nombre}</dd></>}
          {lead.campana && <><dt>Campaña</dt><dd>{lead.campana}</dd></>}
          <dt>Entrada</dt><dd>{formatDateTime(lead.entrada)}{lead.fechaOrigen && <span className="ed-muted"> · rellenó el formulario el {formatDateTime(lead.fechaOrigen)}</span>}</dd>
          {lead.ultimaLlamada && <><dt>Última llamada</dt><dd>{formatDateTime(lead.ultimaLlamada)}</dd></>}
          {lead.estado === 'NO_CONTESTA' && lead.proximaLlamada && <><dt>Volver a llamar</dt><dd>{formatRelative(lead.proximaLlamada)}</dd></>}
          {lead.llamadaAgendada && <><dt>Llamada agendada</dt><dd>{formatDateTime(lead.llamadaAgendada)}</dd></>}
          {lead.citaEn && <><dt>Cita</dt><dd>{formatDateTime(lead.citaEn)}</dd></>}
          {lead.motivoDescarte && <><dt>Motivo descarte</dt><dd>{lead.motivoDescarte}</dd></>}
          {lead.comentarios && <><dt>Comentarios</dt><dd>{lead.comentarios}</dd></>}
          {Object.entries(lead.extra || {}).map(([key, value]) => (
            <FragmentKV key={key} label={key} value={String(value)} />
          ))}
        </dl>
      </section>

      <section className="ed-drawer__section">
        <h3 className="ed-label">Añadir nota</h3>
        <form onSubmit={addNote} className="ed-row" style={{ alignItems: 'stretch', flexWrap: 'nowrap' }}>
          <textarea className="ed-textarea ed-grow" style={{ minHeight: 48 }} value={note} onChange={(e) => setNote(e.target.value)}
            placeholder="Ej.: prefiere que la llamen por la tarde" />
          <button type="submit" className="ed-btn ed-btn--solid" disabled={savingNote || !note.trim()}>
            {savingNote ? <Spinner /> : <Icon name="note" size={14} />} Guardar
          </button>
        </form>
      </section>

      <section className="ed-drawer__section">
        <h3 className="ed-label">Historial</h3>
        <ol className="ed-timeline">
          {lead.historial.map((item) => (
            <li key={item.id} data-type={item.tipo}>
              <div className="ed-medium">{item.resumen}</div>
              <div className="ed-tiny ed-muted">{formatDateTime(item.fecha)}{item.usuario && ` · ${item.usuario}`}</div>
              {(item.detalle?.nota || item.detalle?.texto || item.detalle?.error) && (
                <div className="ed-timeline__note">{item.detalle.nota || item.detalle.texto || item.detalle.error}</div>
              )}
            </li>
          ))}
        </ol>
      </section>

      {calling && (
        <CallMenu lead={lead} anchor={callButton.current} onClose={() => setCalling(false)}
          onMail={() => {
            setCalling(false)
            setDialog('mail')
          }}
          onDone={(updated, next) => {
            setCalling(false)
            apply(updated)
            if (next) setDialog(next)
          }} />
      )}
      {FOLLOW_UPS.includes(dialog) && (
        <FollowUp type={dialog} lead={lead} section={section} onClose={() => setDialog(null)} onSent={(updated) => apply(updated)} />
      )}
      {dialog === 'editar' && (
        <LeadFormModal lead={lead} section={section} onClose={() => setDialog(null)} onSaved={(updated) => apply(updated)} />
      )}
      {dialog === 'cita' && (
        <AppointmentModal lead={lead} section={section} onClose={() => setDialog(null)}
          onSubmit={(fecha, nota) => run(() => leadsApi.appointment(id, fecha, nota), 'Cita agendada')} />
      )}
      {dialog === 'matricula' && (
        <ConfirmModal title="¿Marcar como matriculado?" section={section} confirmLabel="Sí, matriculado" onClose={() => setDialog(null)}
          onConfirm={() => run(() => leadsApi.enroll(id), '¡Matrícula registrada!')}>
          {displayName(lead)} pasará a la pestaña de matriculados.
        </ConfirmModal>
      )}
      {dialog === 'descarte' && (
        <DiscardModal lead={lead} section={section} onClose={() => setDialog(null)}
          onSubmit={(motivo) => run(() => leadsApi.discard(id, motivo), 'Lead descartado (se conserva para campañas)')} />
      )}
    </Drawer>
  )
}

function FragmentKV({ label, value }) {
  return <><dt title="Dato extra del fichero importado">{label}</dt><dd>{value}</dd></>
}

function AppointmentModal({ lead, section, onClose, onSubmit }) {
  const [fecha, setFecha] = useState(toLocalInput(lead.citaEn))
  const [nota, setNota] = useState('')
  return (
    <Modal title="Agendar cita" label={displayName(lead)} section={section} onClose={onClose}
      footer={(
        <>
          <button type="button" className="ed-btn" onClick={onClose}>Cancelar</button>
          <button type="button" className="ed-btn ed-btn--solid" disabled={!fecha} onClick={() => onSubmit(fecha, nota)}>
            <Icon name="calendar" size={14} /> Guardar cita
          </button>
        </>
      )}>
      <div className="ed-stack">
        <Field label="Fecha y hora (Madrid)"><input className="ed-input" type="datetime-local" value={fecha} onChange={(e) => setFecha(e.target.value)} data-autofocus /></Field>
        <Field label="Nota"><textarea className="ed-textarea" value={nota} onChange={(e) => setNota(e.target.value)} placeholder="Visita al centro, entrevista, videollamada…" /></Field>
      </div>
    </Modal>
  )
}

const DISCARD_REASONS = ['No interesado', 'Ya está estudiando en otro centro', 'Precio', 'Datos erróneos', 'Duplicado', 'No cumple requisitos']

function DiscardModal({ lead, section, onClose, onSubmit }) {
  const [motivo, setMotivo] = useState(DISCARD_REASONS[0])
  return (
    <Modal title="Descartar lead" label={displayName(lead)} section={section} onClose={onClose}
      footer={(
        <>
          <button type="button" className="ed-btn" onClick={onClose}>Cancelar</button>
          <button type="button" className="ed-btn ed-btn--solid" onClick={() => onSubmit(motivo)} disabled={!motivo.trim()}>
            <Icon name="ban" size={14} /> Descartar
          </button>
        </>
      )}>
      <div className="ed-stack">
        <p style={{ margin: 0 }}>No se borra: queda en «descartados» para futuras campañas y se puede reabrir.</p>
        <div className="ed-row" style={{ gap: 6 }}>
          {DISCARD_REASONS.map((r) => (
            <button key={r} type="button" className="ed-btn ed-btn--sm" data-on={motivo === r} onClick={() => setMotivo(r)}>{r}</button>
          ))}
        </div>
        <Field label="Motivo"><input className="ed-input" value={motivo} onChange={(e) => setMotivo(e.target.value)} /></Field>
      </div>
    </Modal>
  )
}

function ConfirmModal({ title, children, confirmLabel, section, onClose, onConfirm }) {
  return (
    <Modal title={title} section={section} onClose={onClose}
      footer={(
        <>
          <button type="button" className="ed-btn" onClick={onClose}>Cancelar</button>
          <button type="button" className="ed-btn ed-btn--solid" onClick={onConfirm} data-autofocus>{confirmLabel}</button>
        </>
      )}>
      <p style={{ margin: 0 }}>{children}</p>
    </Modal>
  )
}
