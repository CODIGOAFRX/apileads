import { useCallback, useEffect, useState } from 'react'
import { activityApi } from '../../api/endpoints.js'
import { Icon } from '../../components/Icon.jsx'
import { useToast } from '../../components/Toasts.jsx'
import { Alert, Empty, Loading } from '../../components/ui.jsx'
import { ACTIVITY_GROUPS, filterEvents, shiftDay, summaryLines, toneFor } from '../../lib/activity.js'
import { dayKey, formatTime, plural } from '../../lib/format.js'
import { LeadDrawer } from '../leads/LeadDrawer.jsx'

/**
 * Registro de actividad (solo administración): qué ha hecho cada comercial
 * cada día. Resumen por persona y, debajo, cada acción con su hora.
 */
export function ActivityPage() {
  const toast = useToast()
  const today = dayKey(new Date())
  const [date, setDate] = useState(today)
  const [userId, setUserId] = useState('')
  const [group, setGroup] = useState('')
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [openLead, setOpenLead] = useState(null)

  const load = useCallback(() => activityApi.day(date, userId)
    .then((result) => { setData(result); setError('') })
    .catch((err) => setError(err.message)), [date, userId])

  useEffect(() => { setData(null); load() }, [load])

  // Hoy se va actualizando solo.
  useEffect(() => {
    if (date !== today) return undefined
    const timer = setInterval(load, 60_000)
    return () => clearInterval(timer)
  }, [date, today, load])

  const events = data ? filterEvents(data.eventos, group) : []

  return (
    <div data-section="actividad">
      <div className="ed-track">
        <section className="ed-hero ed-stop">
          <div>
            <p className="ed-label ed-label--dark">Administración</p>
            <h1 className="ed-display ed-hero__title">Actividad</h1>
          </div>
          {data && (
            <div className="ed-hero__count">
              <span className="ed-display">{data.eventos.length}</span>
              <span className="ed-label ed-label--dark">acciones</span>
            </div>
          )}
        </section>

        <div className="ed-toolbar">
          <div className="ed-row" style={{ gap: 6 }}>
            <button type="button" className="ed-icon-btn" onClick={() => setDate(shiftDay(date, -1))} aria-label="Día anterior"><Icon name="chevronLeft" /></button>
            <input className="ed-input" type="date" aria-label="Día" value={date} max={today} onChange={(e) => e.target.value && setDate(e.target.value)} style={{ width: 170 }} />
            <button type="button" className="ed-icon-btn" onClick={() => setDate(shiftDay(date, 1))} disabled={date >= today} aria-label="Día siguiente"><Icon name="chevronRight" /></button>
            {date !== today && <button type="button" className="ed-btn ed-btn--sm" onClick={() => setDate(today)}>Hoy</button>}
          </div>
          <div className="ed-row" style={{ gap: 6 }}>
            <select className="ed-select" aria-label="Usuario" value={userId} onChange={(e) => setUserId(e.target.value)}>
              <option value="">Todo el equipo</option>
              {data?.usuarios.map((u) => <option key={u.id} value={u.id}>{u.nombre}{u.activo ? '' : ' (desactivado)'}</option>)}
            </select>
            <select className="ed-select" aria-label="Acción" value={group} onChange={(e) => setGroup(e.target.value)}>
              <option value="">Todas las acciones</option>
              {ACTIVITY_GROUPS.map((g) => <option key={g.id} value={g.id}>{g.label}</option>)}
            </select>
            <button type="button" className="ed-btn ed-btn--sm" onClick={() => activityApi.export(date, userId).catch(toast.error)}>
              <Icon name="download" size={14} /> CSV
            </button>
          </div>
        </div>

        {error && <Alert tone="error">{error}</Alert>}
        {!data && !error && <Loading />}
        {data && (
          <div className="ed-stack">
            {data.resumen.length === 0
              ? <Empty title="Sin actividad">Nadie ha trabajado en la aplicación este día.</Empty>
              : (
                <div className="ed-grid ed-grid--3">
                  {data.resumen.map((s) => <SummaryCard key={s.usuarioId} summary={s} onSelect={() => setUserId(String(s.usuarioId))} />)}
                </div>
              )}

            {events.length > 0 && (
              <div className="ed-table-wrap">
                <table className="ed-table">
                  <thead><tr><th>Hora</th><th>Usuario</th><th>Acción</th><th>Lead</th><th>Detalle</th></tr></thead>
                  <tbody>
                    {events.map((e) => (
                      <tr key={e.clave}>
                        <td className="ed-mono" style={{ whiteSpace: 'nowrap' }}>{formatTime(e.fecha)}</td>
                        <td style={{ whiteSpace: 'nowrap' }}>{e.usuario ?? '—'}</td>
                        <td><span className={`ed-tag ed-tag--${toneFor(e.tipo)}`}>{e.accion}</span></td>
                        <td>
                          {e.lead
                            ? <button type="button" className="ed-btn ed-btn--sm ed-btn--ghost" style={{ textTransform: 'none', letterSpacing: 0 }} onClick={() => setOpenLead(e.lead.id)}>{e.lead.nombre}</button>
                            : <span className="ed-muted">—</span>}
                        </td>
                        <td className="ed-small">{e.resumen !== e.accion ? e.resumen : ''}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            {data.eventos.length > 0 && events.length === 0 && <p className="ed-muted">No hay acciones de ese tipo este día.</p>}
          </div>
        )}
      </div>
      {openLead && <LeadDrawer id={openLead} section="todos" onClose={() => setOpenLead(null)} onChanged={load} />}
    </div>
  )
}

function SummaryCard({ summary: s, onSelect }) {
  return (
    <div className="ed-panel ed-stack ed-stack--sm">
      <div className="ed-row ed-row--between">
        <button type="button" className="ed-btn ed-btn--sm ed-btn--ghost" style={{ textTransform: 'none', letterSpacing: 0, fontSize: 16, padding: 0 }}
          onClick={onSelect} title="Ver solo sus acciones">
          <span className="ed-strong">{s.usuario}</span>
        </button>
        <span className="ed-tag ed-tag--acc" title="Primera y última acción del día">
          <Icon name="clock" size={12} /> {formatTime(s.primera)} – {formatTime(s.ultima)}
        </span>
      </div>
      <p className="ed-muted ed-small" style={{ margin: 0 }}>{plural(s.acciones, 'acción', 'acciones')} · {plural(s.leads, 'lead trabajado', 'leads trabajados')}</p>
      <dl className="ed-kv" style={{ gridTemplateColumns: 'minmax(0,1fr) auto', fontSize: 13 }}>
        {summaryLines(s).map((line) => (
          <div key={line.label} style={{ display: 'contents' }}>
            <dt>{line.label}{line.extra && <div className="ed-tiny" style={{ textTransform: 'none', letterSpacing: 0 }}>{line.extra}</div>}</dt>
            <dd className="ed-strong" style={{ textAlign: 'right' }}>{line.value}</dd>
          </div>
        ))}
      </dl>
    </div>
  )
}
