import { useEffect, useState } from 'react'
import { mailApi } from '../../api/endpoints.js'
import { useApp } from '../../AppContext.js'
import { Icon } from '../../components/Icon.jsx'
import { useToast } from '../../components/Toasts.jsx'
import { Alert, Field, Loading, Spinner, StudyChip } from '../../components/ui.jsx'
import { HtmlPreview } from '../../components/HtmlPreview.jsx'

// Plantillas que no son de un estudio concreto.
const SPECIAL = {
  generica: { label: 'Genérica (varios estudios)', clave: null },
  'sin-estudio': { label: 'Sin estudio', clave: 'sin-estudio' },
}

/**
 * Plantillas del correo de información. «Sin estudio» es para quien no ha dicho
 * qué quiere estudiar. La genérica se usa cuando tiene varios (o su estudio no
 * tiene plantilla) e incluye los bloques de cada estudio.
 */
export function MailSettings() {
  const { catalog } = useApp()
  const toast = useToast()
  const [data, setData] = useState(null)
  const [selected, setSelected] = useState('generica')
  const [form, setForm] = useState({ asunto: '', cuerpoHtml: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  const load = () => mailApi.templates().then(setData)
  useEffect(() => { load() }, [])

  const special = SPECIAL[selected]
  const studyId = special ? null : Number(selected)
  const template = data?.plantillas.find((t) => (special ? t.estudioId === null && t.clave === special.clave : t.estudioId === studyId))

  useEffect(() => {
    setForm({ asunto: template?.asunto ?? '', cuerpoHtml: template?.cuerpoHtml ?? '' })
    setError('')
  }, [selected, data]) // «template» se deriva de estos dos

  if (!data) return <Loading />

  const save = async () => {
    setBusy(true)
    setError('')
    try {
      setData(await mailApi.save(studyId, form.asunto, form.cuerpoHtml, special?.clave ?? null))
      toast.ok('Plantilla guardada')
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  const remove = async () => {
    await mailApi.remove(studyId).catch(toast.error)
    await load()
    toast.ok('Plantilla eliminada: se usará la genérica')
  }

  const hasTemplate = (id) => data.plantillas.some((t) => t.estudioId === id)

  return (
    <div className="ed-split">
      <div className="ed-stack ed-stack--sm">
        {Object.entries(SPECIAL).map(([key, { label }]) => (
          <button key={key} type="button" className="ed-btn ed-btn--block" data-on={selected === key} onClick={() => setSelected(key)}>{label}</button>
        ))}
        {catalog.estudios.filter((s) => s.activo).map((s) => (
          <button key={s.id} type="button" className="ed-btn ed-btn--block" data-on={selected === String(s.id)}
            style={{ justifyContent: 'space-between', textTransform: 'none', letterSpacing: 0, fontSize: 13 }}
            onClick={() => setSelected(String(s.id))}>
            <StudyChip study={s} />
            {hasTemplate(s.id) ? <Icon name="check" size={14} /> : <span className="ed-muted ed-tiny">genérica</span>}
          </button>
        ))}
      </div>

      <div className="ed-panel ed-stack">
        {error && <Alert tone="error">{error}</Alert>}
        {selected === 'sin-estudio' && (
          <Alert tone="info">Se envía a los leads de los que no sabemos qué quieren estudiar.</Alert>
        )}
        {studyId !== null && !template && (
          <Alert tone="info">Este estudio aún no tiene plantilla propia: sus leads reciben la genérica. Escribe aquí la información del estudio.</Alert>
        )}
        <Field label="Asunto"><input className="ed-input" value={form.asunto} onChange={(e) => setForm({ ...form, asunto: e.target.value })} /></Field>
        <Field label="Contenido (HTML sencillo: <p>, <strong>, <a href>, <ul>…)">
          <textarea className="ed-textarea ed-mono" style={{ minHeight: 260 }} value={form.cuerpoHtml} onChange={(e) => setForm({ ...form, cuerpoHtml: e.target.value })} />
        </Field>
        <div className="ed-panel ed-panel--bone ed-panel--flat">
          <p className="ed-label" style={{ marginTop: 0 }}>Variables disponibles</p>
          <div className="ed-row" style={{ gap: 6 }}>
            {Object.entries(data.variables).map(([name, description]) => (
              <span key={name} className="ed-tag ed-mono" style={{ textTransform: 'none', letterSpacing: 0 }} title={description}>{`{{${name}}}`}</span>
            ))}
          </div>
        </div>
        <p className="ed-label">Vista previa</p>
        <HtmlPreview html={form.cuerpoHtml} />
        <div className="ed-row ed-row--end">
          {studyId !== null && template && <button type="button" className="ed-btn ed-btn--danger" onClick={remove}><Icon name="trash" size={14} /> Quitar plantilla</button>}
          <button type="button" className="ed-btn ed-btn--solid" onClick={save} disabled={busy || !form.asunto.trim() || !form.cuerpoHtml.trim()}>
            {busy && <Spinner />} Guardar plantilla
          </button>
        </div>
      </div>
    </div>
  )
}
