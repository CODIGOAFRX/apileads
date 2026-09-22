import { memo, useRef, useState } from 'react'
import { leadsApi } from '../../api/endpoints.js'
import { Icon } from '../../components/Icon.jsx'
import { useToast } from '../../components/Toasts.jsx'
import { Spinner, StatusTag, StudyList } from '../../components/ui.jsx'
import { formatRelative, formatRelativeTime } from '../../lib/format.js'
import { displayName, followUp, followUpAfter } from '../../lib/leads.js'
import { CallMenu } from './CallMenu.jsx'

function LeadRowView({ lead, onOpen, onChanged, onFollowUp }) {
  const toast = useToast()
  const callButton = useRef(null)
  const [calling, setCalling] = useState(false)
  const [marking, setMarking] = useState(false)
  const can = (action) => lead.acciones.includes(action)
  const lockedByOther = lead.bloqueo && !lead.bloqueo.esMio

  const reopen = async () => {
    try {
      onChanged(await leadsApi.reopen(lead.id))
      toast.ok('Lead reabierto: vuelve a pendientes')
    } catch (err) {
      toast.error(err)
    }
  }

  const markInterested = async () => {
    setMarking(true)
    try {
      const updated = await leadsApi.interested(lead.id)
      toast.ok(`${displayName(updated)} pasa a interesados`)
      onChanged(updated, followUpAfter('INTERESADO', updated))
    } catch (err) {
      toast.error(err)
    } finally {
      setMarking(false)
    }
  }

  const location = [lead.ubicacion, lead.origenLabel].filter(Boolean).join(' · ')
  const scheduled = lead.llamadaAgendada && (
    <span className="ed-lead__scheduled"><Icon name="calendar" size={11} /> Llamar {formatRelativeTime(lead.llamadaAgendada)}</span>
  )

  return (
    <article className="ed-lead" data-locked={Boolean(lockedByOther)} data-due={lead.destacado} aria-label={displayName(lead)}>
      <div className="ed-lead__cell ed-lead__entry">
        <div className="ed-medium ed-small">{formatRelative(lead.entrada)}</div>
        {lead.fechaOrigen && <div className="ed-tiny ed-muted" title="Fecha en la que dejó sus datos">form. {formatRelative(lead.fechaOrigen)}</div>}
      </div>

      <div className="ed-lead__cell">
        <button type="button" className="ed-lead__name ed-ellipsis" onClick={onOpen}>{displayName(lead)}</button>
        <div className="ed-lead__meta">
          {location && <span className="ed-ellipsis">{location}</span>}
          {lockedByOther && <span className="ed-tag ed-tag--warn"><Icon name="phone" size={11} /> {lead.bloqueo.usuario} llamando</span>}
        </div>
        <div className="ed-lead__meta ed-lead__meta--mobile">
          {lead.telefono && <span>{lead.telefono}</span>}
          {lead.email && <span className="ed-ellipsis">{lead.email}</span>}
          <StatusTag lead={lead} />
          {scheduled || <span>{formatRelative(lead.entrada)}</span>}
        </div>
      </div>

      <div className="ed-lead__cell ed-lead__contact">
        {lead.telefono && (
          <div className={`ed-lead__line ${lead.telefonoValido ? '' : 'ed-lead__invalid'}`}>
            <Icon name="phone" size={13} />
            {lead.telUrl ? <a href={lead.telUrl}>{lead.telefono}</a> : <span title="Número no reconocido">{lead.telefono}</span>}
          </div>
        )}
        {lead.email && (
          <div className={`ed-lead__line ${lead.emailValido ? '' : 'ed-lead__invalid'}`} title={lead.emailValido ? lead.email : 'Correo con formato dudoso'}>
            <Icon name="mail" size={13} />
            <span className="ed-ellipsis">{lead.email}</span>
          </div>
        )}
        {!lead.telefono && !lead.email && <span className="ed-muted ed-small">Sin datos de contacto</span>}
      </div>

      <div className="ed-lead__cell ed-lead__studies"><StudyList lead={lead} max={2} /></div>

      <div className="ed-lead__cell ed-lead__status">
        <StatusTag lead={lead} />
        <div className="ed-tiny ed-muted" style={{ marginTop: 4 }}>
          {scheduled}
          {followUp(lead)}
          {lead.estado === 'NO_CONTESTA' && lead.proximaLlamada && lead.bandeja === 'no-contestan' && <> · vuelve {formatRelative(lead.proximaLlamada)}</>}
          {lead.estado === 'CITA' && lead.citaEn && <>Cita {formatRelative(lead.citaEn)}</>}
        </div>
      </div>

      <div className="ed-lead__actions">
        {can('whatsapp') && (
          <a className="ed-icon-btn ed-icon-btn--wa" href={lead.whatsappUrl} target="_blank" rel="noopener noreferrer"
            title="Enviar WhatsApp" aria-label={`Enviar WhatsApp a ${displayName(lead)}`}
            onClick={() => leadsApi.whatsapp(lead.id).catch(() => {})}>
            <Icon name="whatsapp" />
          </a>
        )}
        {can('enviar-info') && (
          <button type="button" className="ed-icon-btn ed-icon-btn--mail" onClick={() => onFollowUp('mail')}
            title="Enviar información por correo" aria-label={`Enviar información a ${displayName(lead)}`}>
            <Icon name="send" />
          </button>
        )}
        {can('interesado') && (
          <button type="button" className="ed-icon-btn ed-icon-btn--ok" onClick={markInterested} disabled={marking}
            title="Marcar como interesado (sin llamar)" aria-label={`Marcar a ${displayName(lead)} como interesado`}>
            {marking ? <Spinner /> : <Icon name="check" strokeWidth={3} />}
          </button>
        )}
        {can('reabrir') && (
          <button type="button" className="ed-btn ed-btn--sm" onClick={reopen}><Icon name="reopen" size={13} /> Reabrir</button>
        )}
        <button type="button" className="ed-icon-btn" onClick={onOpen} title="Ver ficha" aria-label={`Ver ficha de ${displayName(lead)}`}>
          <Icon name="eye" />
        </button>
        {can('llamar') && (
          <button ref={callButton} type="button" className="ed-icon-btn ed-icon-btn--call" onClick={() => setCalling(true)}
            title="Llamada: registrar resultado" aria-label={`Llamar a ${displayName(lead)}`} aria-haspopup="dialog" aria-expanded={calling}>
            <Icon name="phone" size={18} />
            {lead.intentos > 0 && <span className="ed-icon-btn__badge" title="Intentos sin respuesta">{lead.intentos}</span>}
          </button>
        )}
      </div>

      {calling && (
        <CallMenu
          lead={lead}
          anchor={callButton.current}
          onClose={() => setCalling(false)}
          onMail={() => {
            setCalling(false)
            onFollowUp('mail')
          }}
          onDone={(updated, next) => {
            setCalling(false)
            onChanged(updated, next)
          }}
        />
      )}
    </article>
  )
}

export const LeadRow = memo(LeadRowView)
