import { useEffect, useRef, useState } from 'react'
import { Icon } from '../../components/Icon.jsx'
import { Checkbox, Field, StudyPicker } from '../../components/ui.jsx'
import { useApp } from '../../AppContext.js'
import { COMPANIES, EMPTY_FILTERS, STUDY_TYPES, activeFilterCount } from '../../lib/leads.js'

/**
 * Buscador: una caja general ("contiene" en nombre, correo, teléfono, ubicación…)
 * y filtros avanzados por campo, estudio, tipo, empresa, origen y fechas.
 */
export function SearchPanel({ filters, onChange, studies }) {
  const { catalog } = useApp()
  const [open, setOpen] = useState(false)
  const inputRef = useRef(null)
  const active = activeFilterCount(filters)
  const set = (key, value) => onChange({ ...filters, [key]: value })

  // "/" enfoca el buscador desde cualquier parte.
  useEffect(() => {
    const onKey = (event) => {
      const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)
      if (event.key === '/' && !typing && !document.querySelector('[role="dialog"]')) {
        event.preventDefault()
        inputRef.current?.focus()
      }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [])

  const activeStudies = studies.filter((s) => s.activo)

  return (
    <div className="ed-stack ed-stack--sm">
      <div className="ed-row" style={{ flexWrap: 'nowrap' }}>
        <div className="ed-search ed-grow">
          <Icon name="search" size={20} />
          <input
            ref={inputRef}
            type="search"
            value={filters.q}
            onChange={(e) => set('q', e.target.value)}
            placeholder="Buscar por nombre, correo, teléfono (p. ej. 454) o ciudad"
            aria-label="Buscar leads"
            autoComplete="off"
          />
          {filters.q && (
            <button type="button" className="ed-icon-btn ed-icon-btn--sm ed-search__clear" onClick={() => set('q', '')} aria-label="Borrar búsqueda">
              <Icon name="x" size={14} />
            </button>
          )}
        </div>
        <button type="button" className="ed-btn" data-on={open} onClick={() => setOpen(!open)} aria-expanded={open}>
          <Icon name="filter" size={14} />
          <span className="ed-hide-sm">Filtros</span>
          {active > 0 && <span className="ed-tab__badge" style={{ background: 'var(--acc)', color: 'var(--ink)' }}>{active}</span>}
        </button>
      </div>

      <div className="ed-row" style={{ gap: 6 }}>
        <span className="ed-label">Rápido:</span>
        {COMPANIES.map((company) => (
          <button key={company.id} type="button" className="ed-btn ed-btn--sm" data-on={filters.empresa === company.id}
            onClick={() => set('empresa', filters.empresa === company.id ? '' : company.id)} aria-pressed={filters.empresa === company.id}>
            {company.label}
          </button>
        ))}
        {STUDY_TYPES.map((type) => (
          <button key={type.id} type="button" className="ed-btn ed-btn--sm" data-on={filters.tipo === type.id}
            onClick={() => set('tipo', filters.tipo === type.id ? '' : type.id)} aria-pressed={filters.tipo === type.id}>
            <Icon name="graduation" size={13} /> {type.label}
          </button>
        ))}
        {(active > 0 || filters.q) && (
          <button type="button" className="ed-btn ed-btn--sm ed-btn--ghost" onClick={() => onChange(EMPTY_FILTERS)}>
            <Icon name="x" size={13} /> Limpiar todo
          </button>
        )}
      </div>

      {open && (
        <div className="ed-panel ed-panel--bone ed-stack">
          <div className="ed-grid ed-grid--3">
            <Field label="Nombre contiene"><input className="ed-input" value={filters.nombre} onChange={(e) => set('nombre', e.target.value)} placeholder="andres" /></Field>
            <Field label="Correo contiene"><input className="ed-input" value={filters.email} onChange={(e) => set('email', e.target.value)} placeholder="gmail" /></Field>
            <Field label="Teléfono contiene"><input className="ed-input" inputMode="tel" value={filters.telefono} onChange={(e) => set('telefono', e.target.value)} placeholder="454" /></Field>
            <Field label="Ubicación contiene"><input className="ed-input" value={filters.ubicacion} onChange={(e) => set('ubicacion', e.target.value)} placeholder="Granada" /></Field>
            <Field label="Origen">
              <select className="ed-select" value={filters.origen} onChange={(e) => set('origen', e.target.value)}>
                <option value="">Todos</option>
                {(catalog.origenes || []).map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
              </select>
            </Field>
            <Field label="Tipo de estudio">
              <select className="ed-select" value={filters.tipo} onChange={(e) => set('tipo', e.target.value)}>
                <option value="">Todos</option>
                {STUDY_TYPES.map((t) => <option key={t.id} value={t.id}>{t.label}</option>)}
              </select>
            </Field>
            <Field label="Empresa" hint="Según sus estudios de interés">
              <select className="ed-select" value={filters.empresa} onChange={(e) => set('empresa', e.target.value)}>
                <option value="">Las dos</option>
                {COMPANIES.map((c) => <option key={c.id} value={c.id}>{c.label}</option>)}
              </select>
            </Field>
            <Field label="Entrada desde"><input className="ed-input" type="date" value={filters.desde} onChange={(e) => set('desde', e.target.value)} /></Field>
            <Field label="Entrada hasta"><input className="ed-input" type="date" value={filters.hasta} onChange={(e) => set('hasta', e.target.value)} /></Field>
          </div>
          <Field label="Interesados en">
            <StudyPicker studies={activeStudies} value={filters.estudio} onChange={(v) => set('estudio', v)} />
          </Field>
          <Checkbox checked={filters.sinEstudio} onChange={(v) => set('sinEstudio', v)}>Solo leads sin estudio asignado</Checkbox>
        </div>
      )}
    </div>
  )
}
