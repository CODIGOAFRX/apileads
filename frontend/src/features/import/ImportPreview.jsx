import { useState } from 'react'
import { useApp } from '../../AppContext.js'
import { Icon } from '../../components/Icon.jsx'
import { Checkbox, StudyChip } from '../../components/ui.jsx'
import { plural } from '../../lib/format.js'
import { originLabel, suggestStudyName } from '../../lib/leads.js'
import { StudyModal } from '../settings/CatalogSettings.jsx'

/**
 * Estudios escritos de forma que el programa no sabe a cuál corresponden.
 * Administración puede crear el estudio ahí mismo si aún no existe.
 */
export function StudyResolver({ items, studies, value, remember, onChange, onRememberChange }) {
  const { reloadCatalog } = useApp()
  const [creatingFor, setCreatingFor] = useState(null)
  if (!items.length) return null

  const studyCreated = async (study) => {
    const item = creatingFor
    setCreatingFor(null)
    await reloadCatalog().catch(() => {})
    onChange({ ...value, [item.clave]: study.id })
  }

  return (
    <div className="ed-panel ed-stack">
      <div>
        <h3 className="ed-display" style={{ fontSize: 26 }}>¿A qué estudio se refieren?</h3>
        <p className="ed-muted" style={{ margin: '4px 0 0' }}>
          Elige el estudio para cada texto. Lo que dejes sin elegir se importa igual, con el texto guardado como «interés».
        </p>
      </div>
      <div className="ed-table-wrap">
        <table className="ed-table">
          <thead><tr><th>Texto en el fichero</th><th>Filas</th><th>Estudio</th></tr></thead>
          <tbody>
            {items.map((item) => (
              <tr key={item.clave}>
                <td className="ed-medium">«{item.texto}»</td>
                <td>{item.filas}</td>
                <td>
                  <select
                    className="ed-select"
                    value={value[item.clave] ?? ''}
                    onChange={(e) => {
                      const next = { ...value }
                      if (e.target.value === '') delete next[item.clave]
                      else next[item.clave] = Number(e.target.value)
                      onChange(next)
                    }}
                  >
                    <option value="">— Elegir —</option>
                    {studies.map((s) => <option key={s.id} value={s.id}>{s.nombre}</option>)}
                    <option value="0">Ninguno (no es un estudio nuestro)</option>
                  </select>
                  <button type="button" className="ed-btn ed-btn--sm ed-btn--ghost" style={{ marginTop: 6 }} onClick={() => setCreatingFor(item)}>
                    <Icon name="plus" size={13} /> Crear estudio nuevo
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {creatingFor && (
        <StudyModal study={{ nombre: suggestStudyName(creatingFor.texto) }} onClose={() => setCreatingFor(null)} onSaved={studyCreated} />
      )}
      <Checkbox checked={remember} onChange={onRememberChange}>
        Recordar estas equivalencias para próximas importaciones
      </Checkbox>
    </div>
  )
}

/** Qué dato hay en cada columna; se puede corregir. */
export function ColumnMapping({ columns, fields, overrides, onChange }) {
  return (
    <details className="ed-panel">
      <summary style={{ cursor: 'pointer' }}>
        <span className="ed-display" style={{ fontSize: 26 }}>Columnas detectadas</span>{' '}
        <span className="ed-muted">({plural(columns.length, 'columna')} · despliega para revisarlas o corregirlas)</span>
      </summary>
      <div className="ed-table-wrap" style={{ marginTop: 14 }}>
        <table className="ed-table">
          <thead><tr><th>Columna del fichero</th><th>Ejemplos</th><th>Se guarda como</th></tr></thead>
          <tbody>
            {columns.map((column) => (
              <tr key={column.indice}>
                <td className="ed-medium">{column.cabecera}</td>
                <td className="ed-muted">{column.ejemplos.join(' · ') || '—'}</td>
                <td>
                  <select
                    className="ed-select"
                    value={overrides[column.indice] ?? column.campo}
                    onChange={(e) => onChange({ ...overrides, [column.indice]: e.target.value })}
                    aria-label={`Campo para la columna ${column.cabecera}`}
                  >
                    {fields.map((f) => <option key={f.valor} value={f.valor}>{f.etiqueta}</option>)}
                  </select>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </details>
  )
}

/** Primeras filas tal como quedarán guardadas. */
export function SampleTable({ rows, studies }) {
  const { catalog } = useApp()
  if (!rows.length) return null
  const byId = Object.fromEntries(studies.map((s) => [s.id, s]))
  return (
    <div className="ed-stack ed-stack--sm">
      <h3 className="ed-label">Así se guardarán las primeras filas</h3>
      <div className="ed-table-wrap">
        <table className="ed-table">
          <thead><tr><th>Fila</th><th>Nombre</th><th>Teléfono</th><th>Correo</th><th>Ubicación</th><th>Estudio</th><th>Origen</th><th>Avisos</th></tr></thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.fila}>
                <td>{row.fila}</td>
                <td className="ed-medium">{row.nombre || '—'}</td>
                <td>{row.telefono || '—'}</td>
                <td>{row.email || '—'}</td>
                <td>{row.ubicacion || '—'}</td>
                <td>
                  {row.estudioIds.length
                    ? <div className="ed-row" style={{ gap: 4 }}>{row.estudioIds.map((id) => byId[id] && <StudyChip key={id} study={byId[id]} />)}</div>
                    : <span className="ed-muted">{row.interes || '—'}</span>}
                </td>
                <td>{originLabel(catalog?.origenes, row.origen)}</td>
                <td>{row.avisos.length ? <span className="ed-tag ed-tag--warn" title={row.avisos.join('\n')}>{row.avisos.length}</span> : ''}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
