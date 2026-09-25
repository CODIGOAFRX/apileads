import { useState } from 'react'
import { useApp } from '../../AppContext.js'
import { Icon } from '../../components/Icon.jsx'
import { StudyChip } from '../../components/ui.jsx'
import { formatDate } from '../../lib/format.js'
import { StudyModal } from './CatalogSettings.jsx'

/**
 * Estudios de interés para las comerciales: los que hay y un botón para añadir
 * el que falte (p. ej. un curso corto nuevo). Cambiarlos o borrarlos es cosa de
 * administración.
 */
export function StudiesOverview() {
  const { catalog, reloadCatalog } = useApp()
  const [creating, setCreating] = useState(false)
  const active = catalog.estudios.filter((s) => s.activo)
  const groups = Object.entries(catalog.tipos)
    .map(([type, label]) => ({ type, label, studies: active.filter((s) => s.tipo === type) }))
    .filter((g) => g.studies.length > 0)

  return (
    <div className="ed-stack">
      <div className="ed-row ed-row--between">
        <p className="ed-muted" style={{ margin: 0 }}>
          Si un lead pide algo que no está en la lista (un curso corto nuevo, por ejemplo), créalo aquí o desde su ficha.
        </p>
        <button type="button" className="ed-btn ed-btn--solid ed-btn--sm" onClick={() => setCreating(true)}>
          <Icon name="plus" size={14} /> Nuevo estudio
        </button>
      </div>
      {groups.map((group) => (
        <div key={group.type} className="ed-panel ed-stack ed-stack--sm">
          <p className="ed-label" style={{ margin: 0 }}>{group.label} · {group.studies.length}</p>
          <div className="ed-row" style={{ gap: 6 }}>
            {group.studies.map((s) => (
              <span key={s.id} title={s.creadoPor ? `Añadido por ${s.creadoPor}${s.creadoEn ? ` el ${formatDate(s.creadoEn)}` : ''}` : undefined}>
                <StudyChip study={s} />
              </span>
            ))}
          </div>
        </div>
      ))}
      {creating && <StudyModal study={{}} onClose={() => setCreating(false)} onSaved={() => { setCreating(false); reloadCatalog() }} />}
    </div>
  )
}
