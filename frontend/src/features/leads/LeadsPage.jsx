import { useCallback, useEffect, useRef, useState } from 'react'
import { leadsApi } from '../../api/endpoints.js'
import { useApp } from '../../AppContext.js'
import { Icon } from '../../components/Icon.jsx'
import { useToast } from '../../components/Toasts.jsx'
import { Empty, Loading, Spinner, Alert } from '../../components/ui.jsx'
import { useDebouncedValue } from '../../hooks/useDebouncedValue.js'
import { EMPTY_FILTERS, INBOXES, activeFilterCount, buildLeadQuery } from '../../lib/leads.js'
import { plural } from '../../lib/format.js'
import { SearchPanel } from './SearchPanel.jsx'
import { LeadRow } from './LeadRow.jsx'
import { LeadDrawer } from './LeadDrawer.jsx'
import { LeadFormModal } from './LeadFormModal.jsx'
import { FollowUp } from './FollowUpModals.jsx'

const PER_PAGE = 50
const REFRESH_MS = 60_000

const SORTS = [
  { field: 'entrada', label: 'Entrada' },
  { field: 'nombre', label: 'Nombre' },
  { field: 'proxima', label: 'Próxima llamada' },
  { field: 'ultima-llamada', label: 'Última llamada' },
]

export function LeadsPage() {
  const { catalog } = useApp()
  const toast = useToast()
  const [inbox, setInbox] = useState('pendientes')
  const [filters, setFilters] = useState(EMPTY_FILTERS)
  const [page, setPage] = useState(1)
  const [sort, setSort] = useState({ field: 'entrada', dir: 'desc' })
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [openId, setOpenId] = useState(null)
  const [creating, setCreating] = useState(false)
  const [exporting, setExporting] = useState(false)
  const [followUp, setFollowUp] = useState(null)
  const requestId = useRef(0)

  const debouncedFilters = useDebouncedValue(filters, 250)
  const query = buildLeadQuery({ inbox, filters: debouncedFilters, page, perPage: PER_PAGE, sort })
  const queryKey = JSON.stringify(query)

  const load = useCallback(async (silent = false) => {
    const id = ++requestId.current
    if (!silent) setLoading(true)
    try {
      const result = await leadsApi.list(JSON.parse(queryKey))
      if (id !== requestId.current) return
      setData(result)
      setError('')
    } catch (err) {
      if (id === requestId.current) setError(err.message)
    } finally {
      if (id === requestId.current) setLoading(false)
    }
  }, [queryKey])

  useEffect(() => { load() }, [load])

  // Refresco periódico y al volver a la pestaña: así aparecen los que vuelven a las 9:00
  // y los cambios de las compañeras sin recargar.
  useEffect(() => {
    const refresh = () => document.visibilityState === 'visible' && load(true)
    const timer = setInterval(refresh, REFRESH_MS)
    window.addEventListener('focus', refresh)
    return () => {
      clearInterval(timer)
      window.removeEventListener('focus', refresh)
    }
  }, [load])

  const changeInbox = (id) => {
    setInbox(id)
    setPage(1)
  }
  const changeFilters = (next) => {
    setFilters(next)
    setPage(1)
  }

  /** Tras una acción, actualiza la fila (o la quita si cambió de pestaña) y los contadores. */
  const onLeadChanged = useCallback((lead, next) => {
    if (next) setFollowUp({ type: next, lead })
    setData((current) => {
      if (!current) return current
      const staysHere = inbox === 'todos' || lead.bandeja === inbox
      const items = staysHere
        ? current.items.map((item) => (item.id === lead.id ? lead : item))
        : current.items.filter((item) => item.id !== lead.id)
      return { ...current, items, total: current.total - (current.items.length - items.length) }
    })
    leadsApi.counters().then((contadores) => setData((d) => (d ? { ...d, contadores } : d))).catch(() => {})
  }, [inbox])

  const exportCsv = async () => {
    setExporting(true)
    try {
      await leadsApi.export({ ...query, pagina: undefined, porPagina: undefined })
    } catch (err) {
      toast.error(err)
    } finally {
      setExporting(false)
    }
  }

  const current = INBOXES.find((i) => i.id === inbox)
  const unfiltered = activeFilterCount(filters) === 0 && !filters.q.trim()
  const counters = data?.contadores || {}
  const totalPages = data ? Math.max(1, Math.ceil(data.total / PER_PAGE)) : 1

  return (
    <div data-section={inbox}>
      <nav className="ed-tabs" aria-label="Pestañas de leads">
        {INBOXES.map((tab) => (
          <button key={tab.id} type="button" className="ed-tab" data-tab={tab.id} data-on={inbox === tab.id}
            onClick={() => changeInbox(tab.id)} aria-pressed={inbox === tab.id} title={tab.hint}>
            {tab.label}
            {!['historico', 'todos'].includes(tab.id) && counters[tab.id] > 0 && <span className="ed-tab__badge">{counters[tab.id]}</span>}
          </button>
        ))}
      </nav>

      <div className="ed-track">
        <section className="ed-hero ed-stop">
          <div>
            <p className="ed-label ed-label--dark">{current.hint}</p>
            <h1 className="ed-display ed-hero__title">{current.title}</h1>
          </div>
          <div className="ed-hero__count">
            <span className="ed-display">{data ? data.total : '–'}</span>
            <span className="ed-label ed-label--dark">{data?.total === 1 ? 'lead' : 'leads'}</span>
          </div>
        </section>

        <SearchPanel filters={filters} onChange={changeFilters} studies={catalog.estudios} />

        <div className="ed-toolbar">
          <div className="ed-row">
            {inbox === 'agendadas' ? (
              <span className="ed-label">Por día y hora de la llamada</span>
            ) : (
              <>
                <label className="ed-label" htmlFor="lead-sort">Ordenar</label>
                <select id="lead-sort" className="ed-select" style={{ width: 'auto' }} value={sort.field}
                  onChange={(e) => { setSort({ field: e.target.value, dir: e.target.value === 'nombre' || e.target.value === 'proxima' ? 'asc' : 'desc' }); setPage(1) }}>
                  {SORTS.map((s) => <option key={s.field} value={s.field}>{s.label}</option>)}
                </select>
                <button type="button" className="ed-icon-btn" onClick={() => setSort((s) => ({ ...s, dir: s.dir === 'asc' ? 'desc' : 'asc' }))}
                  title={sort.dir === 'asc' ? 'Ascendente' : 'Descendente'} aria-label="Cambiar sentido del orden">
                  <Icon name={sort.dir === 'asc' ? 'arrowUp' : 'arrowDown'} />
                </button>
              </>
            )}
            {loading && data && <Spinner label="Actualizando" />}
          </div>
          <div className="ed-row">
            <button type="button" className="ed-btn ed-btn--sm" onClick={exportCsv} disabled={exporting || !data?.total}>
              {exporting ? <Spinner /> : <Icon name="download" size={14} />} Exportar CSV
            </button>
            <button type="button" className="ed-btn ed-btn--sm ed-btn--solid" onClick={() => setCreating(true)}>
              <Icon name="plus" size={14} /> Nuevo lead
            </button>
          </div>
        </div>

        {error && <Alert tone="error">{error} <button type="button" className="ed-btn ed-btn--sm" onClick={() => load()}>Reintentar</button></Alert>}

        {!data && loading ? <Loading>Cargando leads…</Loading> : data && (
          data.items.length === 0 ? (
            <Empty title={unfiltered ? `No hay leads en «${current.title}»` : 'Ningún lead coincide con la búsqueda'}>
              {inbox === 'pendientes' && unfiltered
                ? 'Buen trabajo: no queda nadie por llamar ahora mismo.'
                : 'Prueba con otra pestaña o con menos filtros.'}
            </Empty>
          ) : (
            <div className="ed-list">
              <div className="ed-list__head ed-label" aria-hidden="true">
                <span>Entrada</span><span>Lead</span><span>Contacto</span><span>Estudios</span><span>Seguimiento</span><span style={{ textAlign: 'right' }}>Acciones</span>
              </div>
              {data.items.map((lead) => (
                <LeadRow key={lead.id} lead={lead} onOpen={() => setOpenId(lead.id)} onChanged={onLeadChanged}
                  onFollowUp={(type) => setFollowUp({ type, lead })} />
              ))}
            </div>
          )
        )}

        {data && data.total > PER_PAGE && (
          <nav className="ed-pager" aria-label="Paginación">
            <button type="button" className="ed-icon-btn" disabled={page <= 1} onClick={() => setPage(page - 1)} aria-label="Página anterior">
              <Icon name="chevronLeft" />
            </button>
            <span className="ed-medium">Página {page} de {totalPages} · {plural(data.total, 'lead')}</span>
            <button type="button" className="ed-icon-btn" disabled={page >= totalPages} onClick={() => setPage(page + 1)} aria-label="Página siguiente">
              <Icon name="chevronRight" />
            </button>
          </nav>
        )}
      </div>

      {openId && (
        <LeadDrawer id={openId} section={inbox} onClose={() => setOpenId(null)} onChanged={onLeadChanged} />
      )}
      {followUp && (
        <FollowUp type={followUp.type} lead={followUp.lead} section={inbox} onClose={() => setFollowUp(null)}
          onSent={(lead) => { setFollowUp(null); onLeadChanged(lead) }} />
      )}
      {creating && (
        <LeadFormModal
          section={inbox}
          onClose={() => setCreating(false)}
          onSaved={(lead) => {
            setCreating(false)
            load(true)
            setOpenId(lead.id)
          }}
          onOpenExisting={(id) => { setCreating(false); setOpenId(id) }}
        />
      )}
    </div>
  )
}
