// Piezas pequeñas de interfaz reutilizadas en toda la aplicación.
import { Icon } from './Icon.jsx'
import { cn } from '../lib/cn.js'
import { STATUS_TONE } from '../lib/leads.js'

export function Spinner({ label }) {
  return <span className="ed-spinner" role="status" aria-label={label || 'Cargando'} />
}

export function Loading({ children = 'Cargando…' }) {
  return <div className="ed-loading"><Spinner />{children}</div>
}

export function Empty({ title, children }) {
  return <div className="ed-empty"><strong>{title}</strong>{children}</div>
}

export function Alert({ tone = 'warn', children }) {
  return (
    <div className={cn('ed-alert', tone !== 'warn' && `ed-alert--${tone}`)} role={tone === 'error' ? 'alert' : undefined}>
      <Icon name={tone === 'ok' ? 'check' : 'alert'} />
      <div>{children}</div>
    </div>
  )
}

export function Field({ label, hint, error, children, htmlFor }) {
  return (
    <div className="ed-field">
      {label && <label className="ed-label" htmlFor={htmlFor}>{label}</label>}
      {children}
      {error ? <span className="ed-error-text">{error}</span> : hint && <span className="ed-hint">{hint}</span>}
    </div>
  )
}

export function Checkbox({ checked, onChange, children }) {
  return (
    <label className="ed-check">
      <input type="checkbox" className="sr-only" checked={checked} onChange={(e) => onChange(e.target.checked)} />
      <span className="ed-check__box">{checked && <Icon name="check" size={13} strokeWidth={3} />}</span>
      <span>{children}</span>
    </label>
  )
}

export function StudyChip({ study }) {
  return (
    <span className="ed-chip" title={study.nombre}>
      <span className="ed-chip__dot" style={{ background: study.color }} />
      <span className="ed-chip__text">{study.nombre}</span>
    </span>
  )
}

export function StudyList({ lead, max = 3 }) {
  const studies = lead.estudios || []
  if (!studies.length) {
    return lead.interes
      ? <span className="ed-chip ed-chip--pending" title="Estudio sin asignar">{lead.interes}</span>
      : <span className="ed-muted ed-small">—</span>
  }
  const shown = studies.slice(0, max)
  return (
    <div className="ed-row" style={{ gap: 4 }}>
      {shown.map((s) => <StudyChip key={s.id} study={s} />)}
      {studies.length > max && <span className="ed-tag ed-tag--ghost">+{studies.length - max}</span>}
    </div>
  )
}

export function StatusTag({ lead }) {
  return <span className={`ed-tag ed-tag--${STATUS_TONE[lead.estado] || 'ghost'}`}>{lead.estadoLabel}</span>
}

/** Selector de estudios con los colores de cada titulación. */
export function StudyPicker({ studies, value, onChange }) {
  const toggle = (id) => onChange(value.includes(id) ? value.filter((v) => v !== id) : [...value, id])
  return (
    <div className="ed-studypick">
      {studies.map((study) => (
        <button
          key={study.id}
          type="button"
          aria-pressed={value.includes(study.id)}
          style={{ '--sc': study.color }}
          onClick={() => toggle(study.id)}
        >
          <span className="ed-chip__dot" style={{ background: study.color }} />
          {study.nombre}
        </button>
      ))}
    </div>
  )
}
