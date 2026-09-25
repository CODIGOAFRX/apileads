import { useEffect, useRef, useState } from 'react'
import { importApi } from '../../api/endpoints.js'
import { useApp } from '../../AppContext.js'
import { Icon } from '../../components/Icon.jsx'
import { useToast } from '../../components/Toasts.jsx'
import { Alert, Field, Loading, Spinner } from '../../components/ui.jsx'
import { formatDateTime, plural } from '../../lib/format.js'
import { originLabel, selectableOrigins } from '../../lib/leads.js'
import { ColumnMapping, SampleTable, StudyResolver } from './ImportPreview.jsx'

const ACCEPT = '.csv,.txt,.tsv,.xlsx,.xls,.xlsm,.ods'
const STEPS = ['Fichero', 'Revisar', 'Resultado']

/**
 * Importación en tres pasos: elegir fichero → revisar lo que ha entendido el
 * programa (columnas y estudios) → importar y ver el informe.
 */
export function ImportPage({ onDone }) {
  const { catalog, reloadCatalog } = useApp()
  const toast = useToast()
  const inputRef = useRef(null)
  const [file, setFile] = useState(null)
  const [dragOver, setDragOver] = useState(false)
  const [options, setOptions] = useState({ origen: '', centroId: '', columnas: {}, estudios: {}, guardarAlias: true })
  const [preview, setPreview] = useState(null)
  const [report, setReport] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [history, setHistory] = useState(null)

  useEffect(() => { importApi.history().then(setHistory).catch(() => setHistory([])) }, [report])

  const step = report ? 2 : preview ? 1 : 0

  const analyse = async (selected, nextOptions) => {
    setBusy(true)
    setError('')
    try {
      const result = await importApi.preview(selected, cleanOptions(nextOptions))
      setPreview(result)
      if (!nextOptions.origen) {
        setOptions((o) => ({ ...o, origen: result.origenDetectado === 'OTRO' ? '' : result.origenDetectado }))
      }
    } catch (err) {
      setError(err.message)
      setPreview(null)
    } finally {
      setBusy(false)
    }
  }

  const choose = (selected) => {
    if (!selected) return
    const fresh = { origen: '', centroId: '', columnas: {}, estudios: {}, guardarAlias: true }
    setFile(selected)
    setOptions(fresh)
    setReport(null)
    analyse(selected, fresh)
  }

  const updateOptions = (patch, reanalyse = false) => {
    const next = { ...options, ...patch }
    setOptions(next)
    if (reanalyse) analyse(file, next)
  }

  const runImport = async () => {
    setBusy(true)
    setError('')
    try {
      const result = await importApi.run(file, cleanOptions(options))
      setReport(result)
      toast.ok(`Importación terminada: ${plural(result.creados, 'lead nuevo', 'leads nuevos')}`)
      if (Object.keys(options.estudios).length) reloadCatalog()
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  const reset = () => {
    setFile(null)
    setPreview(null)
    setReport(null)
    setError('')
    if (inputRef.current) inputRef.current.value = ''
  }

  const pending = preview?.estudiosSinReconocer.filter((item) => !(item.clave in options.estudios)) ?? []

  return (
    <div data-section="importar">
      <div className="ed-track">
        <section className="ed-hero ed-stop">
          <div>
            <p className="ed-label ed-label--dark">CSV o Excel · web, Facebook, Instagram, ferias…</p>
            <h1 className="ed-display ed-hero__title">Importar leads</h1>
          </div>
          <div className="ed-row">
            <button type="button" className="ed-btn ed-btn--sm" onClick={() => importApi.template('xlsx').catch(toast.error)}>
              <Icon name="download" size={14} /> Plantilla Excel
            </button>
            <button type="button" className="ed-btn ed-btn--sm" onClick={() => importApi.template('csv').catch(toast.error)}>
              <Icon name="download" size={14} /> Plantilla CSV
            </button>
          </div>
        </section>

        <div className="ed-steps" aria-label="Pasos">
          {STEPS.map((label, index) => (
            <span key={label} data-on={index <= step}><b>{index + 1}</b>{label}{index < STEPS.length - 1 && <Icon name="chevronRight" size={14} />}</span>
          ))}
        </div>

        {error && <div style={{ marginBottom: 14 }}><Alert tone="error">{error}</Alert></div>}

        {step === 0 && (
          <div className="ed-stack">
            <div
              className="ed-drop"
              data-over={dragOver}
              role="button"
              tabIndex={0}
              onClick={() => inputRef.current?.click()}
              onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && inputRef.current?.click()}
              onDragOver={(e) => { e.preventDefault(); setDragOver(true) }}
              onDragLeave={() => setDragOver(false)}
              onDrop={(e) => { e.preventDefault(); setDragOver(false); choose(e.dataTransfer.files?.[0]) }}
            >
              {busy ? <Spinner /> : <Icon name="upload" size={40} strokeWidth={1.6} />}
              <span className="ed-display" style={{ fontSize: 34 }}>{busy ? 'Analizando…' : 'Arrastra aquí el fichero'}</span>
              <span className="ed-muted">o haz clic para elegirlo · CSV, TXT, XLSX, XLS u ODS · máx. 10 MB</span>
              <input ref={inputRef} type="file" accept={ACCEPT} hidden onChange={(e) => choose(e.target.files?.[0])} />
            </div>
            <Alert tone="info">
              No hace falta que el fichero siga la plantilla: el programa reconoce las columnas por su nombre o por su contenido,
              acepta filas incompletas y no duplica a quien ya existe (completa su ficha).
            </Alert>
          </div>
        )}

        {step === 1 && preview && (
          <div className="ed-stack">
            <div className="ed-panel ed-panel--flat ed-row ed-row--between">
              <div className="ed-row">
                <Icon name="file" size={22} />
                <div>
                  <div className="ed-strong">{file.name}</div>
                  <div className="ed-tiny ed-muted">
                    {preview.filaCabecera ? `Cabecera en la fila ${preview.filaCabecera}` : 'Sin fila de cabecera: columnas deducidas por su contenido'}
                  </div>
                </div>
              </div>
              <button type="button" className="ed-btn ed-btn--sm" onClick={reset}><Icon name="x" size={13} /> Cambiar fichero</button>
            </div>

            <div className="ed-grid ed-grid--3">
              <Stat label="Filas" value={preview.totalFilas} />
              <Stat label="Se importarán" value={preview.importables} tone="acc" />
              <Stat label="Ya existen · se completarán" value={preview.yaExisten + preview.repetidosEnFichero} />
              <Stat label="Sin nombre, correo ni teléfono · se omiten" value={preview.sinContacto} tone={preview.sinContacto ? 'busy' : undefined} />
            </div>

            {preview.faltan.length > 0 && (
              <Alert>
                No se ha encontrado columna de <strong>{preview.faltan.join(', ')}</strong>. Se importará igualmente; si alguna columna
                sí contiene ese dato, asígnala abajo.
              </Alert>
            )}
            {preview.estudioDesde && (
              <Alert tone="info">
                El fichero no tiene columna de estudio: se deduce de la columna <strong>«{preview.estudioDesde}»</strong>.
              </Alert>
            )}

            <div className="ed-panel ed-grid ed-grid--2">
              <Field label="Origen de estos leads" hint="Se usa en las filas que no indican su origen">
                <select className="ed-select" value={options.origen} onChange={(e) => updateOptions({ origen: e.target.value }, true)}>
                  <option value="">Sin especificar</option>
                  {selectableOrigins(catalog.origenes).map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
                </select>
              </Field>
              {catalog.centros.length > 1 && (
                <Field label="Centro">
                  <select className="ed-select" value={options.centroId} onChange={(e) => updateOptions({ centroId: e.target.value })}>
                    <option value="">Sin asignar</option>
                    {catalog.centros.filter((c) => c.activo).map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
                  </select>
                </Field>
              )}
            </div>

            <StudyResolver
              items={preview.estudiosSinReconocer}
              studies={catalog.estudios.filter((s) => s.activo)}
              value={options.estudios}
              remember={options.guardarAlias}
              onChange={(estudios) => updateOptions({ estudios })}
              onRememberChange={(guardarAlias) => updateOptions({ guardarAlias })}
            />

            <ColumnMapping
              columns={preview.columnas}
              fields={preview.campos}
              overrides={options.columnas}
              onChange={(columnas) => updateOptions({ columnas }, true)}
            />

            <SampleTable rows={preview.muestra} studies={catalog.estudios} />

            <div className="ed-row ed-row--end">
              {pending.length > 0 && <span className="ed-hint">{plural(pending.length, 'estudio')} sin asignar: esos leads entrarán sin estudio.</span>}
              <button type="button" className="ed-btn" onClick={reset}>Cancelar</button>
              <button type="button" className="ed-btn ed-btn--solid ed-btn--lg" onClick={runImport} disabled={busy || preview.importables === 0}>
                {busy ? <Spinner /> : <Icon name="upload" size={14} />} Importar {plural(preview.importables, 'lead')}
              </button>
            </div>
          </div>
        )}

        {step === 2 && report && (
          <div className="ed-stack">
            <div className="ed-grid ed-grid--3">
              <Stat label="Nuevos" value={report.creados} tone="acc" />
              <Stat label="Completados (ya existían)" value={report.fusionados} />
              <Stat label="Vuelven a pendientes" value={report.reactivados} />
              <Stat label="Omitidos" value={report.omitidos} tone={report.omitidos ? 'busy' : undefined} />
            </div>
            {report.incidencias.length > 0 && (
              <div className="ed-stack ed-stack--sm">
                <h3 className="ed-label">Incidencias ({report.incidencias.length + report.incidenciasOcultas})</h3>
                <div className="ed-table-wrap">
                  <table className="ed-table">
                    <thead><tr><th>Fila</th><th>Tipo</th><th>Detalle</th></tr></thead>
                    <tbody>
                      {report.incidencias.map((item, index) => (
                        <tr key={index}>
                          <td>{item.fila}</td>
                          <td><span className={`ed-tag ed-tag--${item.tipo === 'aviso' ? 'warn' : 'busy'}`}>{item.tipo}</span></td>
                          <td>{item.mensaje}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}
            <div className="ed-row ed-row--end">
              <button type="button" className="ed-btn" onClick={reset}><Icon name="upload" size={14} /> Importar otro fichero</button>
              <button type="button" className="ed-btn ed-btn--solid" onClick={onDone}><Icon name="list" size={14} /> Ir a los leads</button>
            </div>
          </div>
        )}

        <section className="ed-stack ed-stack--sm" style={{ marginTop: 36 }}>
          <h3 className="ed-label">Últimas importaciones</h3>
          {!history ? <Loading /> : history.length === 0 ? <p className="ed-muted">Todavía no se ha importado nada.</p> : (
            <div className="ed-table-wrap">
              <table className="ed-table">
                <thead><tr><th>Fecha</th><th>Fichero</th><th>Origen</th><th>Por</th><th>Filas</th><th>Nuevos</th><th>Completados</th><th>Omitidos</th></tr></thead>
                <tbody>
                  {history.map((item) => (
                    <tr key={item.id}>
                      <td>{formatDateTime(item.fecha)}</td>
                      <td>{item.archivo}</td>
                      <td>{originLabel(catalog.origenes, item.origen)}</td>
                      <td>{item.usuario ?? '—'}</td>
                      <td>{item.totalFilas}</td>
                      <td>{item.creados}</td>
                      <td>{item.fusionados}</td>
                      <td>{item.omitidos}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </div>
    </div>
  )
}

function Stat({ label, value, tone }) {
  return (
    <div className={`ed-stat ${tone ? `ed-stat--${tone}` : ''}`}>
      <div className="ed-label ed-label--dark">{label}</div>
      <div className="ed-stat__value">{value}</div>
    </div>
  )
}

function cleanOptions(options) {
  return {
    ...options,
    origen: options.origen || undefined,
    centroId: options.centroId || undefined,
  }
}
