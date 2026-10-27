import { useState } from 'react'
import { catalogApi } from '../../api/endpoints.js'
import { useApp } from '../../AppContext.js'
import { Icon } from '../../components/Icon.jsx'
import { Modal } from '../../components/Modal.jsx'
import { useToast } from '../../components/Toasts.jsx'
import { Alert, Checkbox, CompanyTag, Field, Spinner, StudyChip } from '../../components/ui.jsx'
import { formatDateTime } from '../../lib/format.js'
import { COMPANIES } from '../../lib/leads.js'

const PALETTE = ['#00FF81', '#FF79F5', '#FF7A78', '#8A86FF', '#FFFF82', '#8CE5FF', '#D06EFA', '#FFA83D', '#A9E58A', '#FF9E7A', '#5B84E8']

export function CatalogSettings() {
  const { catalog, reloadCatalog } = useApp()
  const [study, setStudy] = useState(null)
  const [center, setCenter] = useState(null)
  const centerName = (id) => catalog.centros.find((c) => c.id === id)?.nombre

  return (
    <div className="ed-stack">
      <Alert tone="info">
        Cada estudio es de una <strong>empresa</strong>: un lead es de Estación Diseño si alguno de sus estudios lo es y, si no, de Grupo EFP.
        Tiene también un <strong>tipo</strong> (para filtrar «todos los de máster») y un <strong>área</strong> (para reconocerlo al importar).
        Los <strong>alias</strong> son formas en que llega escrito en los ficheros; se aprenden solos al importar.
      </Alert>

      <div className="ed-row ed-row--between">
        <h2 className="ed-display" style={{ fontSize: 30 }}>Estudios</h2>
        <button type="button" className="ed-btn ed-btn--solid ed-btn--sm" onClick={() => setStudy({})}><Icon name="plus" size={14} /> Nuevo estudio</button>
      </div>
      <div className="ed-table-wrap">
        <table className="ed-table">
          <thead><tr><th>Estudio</th><th>Empresa</th><th>Tipo</th><th>Área</th><th>Centros</th><th>Alias</th><th>Creado por</th><th /></tr></thead>
          <tbody>
            {catalog.estudios.map((s) => (
              <tr key={s.id} style={{ opacity: s.activo ? 1 : 0.5 }}>
                <td><StudyChip study={s} />{!s.activo && <span className="ed-tag ed-tag--ghost" style={{ marginLeft: 6 }}>inactivo</span>}</td>
                <td><CompanyTag company={s.empresa} /></td>
                <td>{catalog.tipos[s.tipo] ?? s.tipo}</td>
                <td>{catalog.areas[s.area] ?? '—'}</td>
                <td>{s.centroIds.map(centerName).join(', ') || '—'}</td>
                <td className="ed-muted">{s.alias.length}</td>
                <td className="ed-small">{s.creadoPor ? <span title={s.creadoEn ? formatDateTime(s.creadoEn) : undefined}>{s.creadoPor}</span> : <span className="ed-muted">—</span>}</td>
                <td style={{ textAlign: 'right' }}>
                  <button type="button" className="ed-icon-btn ed-icon-btn--sm" onClick={() => setStudy(s)} aria-label={`Editar ${s.nombre}`}><Icon name="edit" size={14} /></button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="ed-row ed-row--between" style={{ marginTop: 16 }}>
        <h2 className="ed-display" style={{ fontSize: 30 }}>Centros</h2>
        <button type="button" className="ed-btn ed-btn--solid ed-btn--sm" onClick={() => setCenter({})}><Icon name="plus" size={14} /> Nuevo centro</button>
      </div>
      <p className="ed-muted" style={{ margin: 0 }}>Preparado para el reparto automático por estudios y ubicación entre escuelas.</p>
      <div className="ed-table-wrap">
        <table className="ed-table">
          <thead><tr><th>Centro</th><th>Ciudad</th><th>Provincia</th><th>Estado</th><th /></tr></thead>
          <tbody>
            {catalog.centros.map((c) => (
              <tr key={c.id}>
                <td className="ed-medium">{c.nombre}</td>
                <td>{c.ciudad || '—'}</td>
                <td>{c.provincia || '—'}</td>
                <td>{c.activo ? <span className="ed-tag ed-tag--ok">Activo</span> : <span className="ed-tag ed-tag--ghost">Inactivo</span>}</td>
                <td style={{ textAlign: 'right' }}>
                  <button type="button" className="ed-icon-btn ed-icon-btn--sm" onClick={() => setCenter(c)} aria-label={`Editar ${c.nombre}`}><Icon name="edit" size={14} /></button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {study && <StudyModal study={study} onClose={() => setStudy(null)} onSaved={() => { setStudy(null); reloadCatalog() }} onAliasChange={reloadCatalog} />}
      {center && <CenterModal center={center} onClose={() => setCenter(null)} onSaved={() => { setCenter(null); reloadCatalog() }} />}
    </div>
  )
}

/**
 * Alta o edición de un estudio. También se abre desde la ficha de un lead y desde
 * la importación. Una comercial solo da de alta (p. ej. un curso corto nuevo) con
 * lo básico; si ya existe uno igual, se le ofrece en lugar de duplicarlo.
 */
export function StudyModal({ study, onClose, onSaved, onAliasChange = () => {} }) {
  const { catalog, isAdmin, user } = useApp()
  const toast = useToast()
  const isNew = !study.id
  const [existing, setExisting] = useState(null)
  const [form, setForm] = useState({
    nombre: study.nombre || '', tipo: study.tipo || (isAdmin ? 'GRADO' : 'CURSO'), area: study.area || '', empresa: study.empresa || user?.empresa || 'EFP',
    color: study.color || PALETTE[catalog.estudios.length % PALETTE.length],
    orden: study.orden ?? catalog.estudios.length + 1, activo: study.activo ?? true,
    centroIds: study.centroIds || catalog.centros.map((c) => c.id),
  })
  const [aliases, setAliases] = useState(study.alias || [])
  const [newAlias, setNewAlias] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const set = (key) => (e) => setForm({ ...form, [key]: e?.target ? e.target.value : e })

  const save = async () => {
    setBusy(true)
    setError('')
    setExisting(null)
    try {
      // Una comercial no elige orden, centros ni si está activo: lo decide el servidor.
      const payload = isAdmin ? form : { nombre: form.nombre, tipo: form.tipo, area: form.area, color: form.color, empresa: form.empresa }
      const saved = isNew ? await catalogApi.createStudy(payload) : await catalogApi.updateStudy(study.id, payload)
      toast.ok(isNew ? `«${saved.nombre}» creado` : 'Estudio guardado')
      onSaved(saved)
    } catch (err) {
      if (err.status === 409 && err.details?.estudio) setExisting(err.details.estudio)
      else setError(err.message)
      setBusy(false)
    }
  }

  const useExisting = () => {
    toast.ok(`Se usa «${existing.nombre}», que ya existía`)
    onSaved(existing)
  }

  const remove = async () => {
    try {
      await catalogApi.deleteStudy(study.id)
      toast.ok('Estudio eliminado')
      onSaved()
    } catch (err) {
      setError(err.message)
    }
  }

  const addAlias = async (event) => {
    event.preventDefault()
    try {
      const updated = await catalogApi.addAlias(study.id, newAlias)
      setAliases(updated.alias)
      setNewAlias('')
      onAliasChange()
    } catch (err) {
      setError(err.message)
    }
  }

  const removeAlias = async (id) => {
    await catalogApi.deleteAlias(id).catch((err) => setError(err.message))
    setAliases(aliases.filter((a) => a.id !== id))
    onAliasChange()
  }

  const toggleCenter = (id) => set('centroIds')(form.centroIds.includes(id) ? form.centroIds.filter((c) => c !== id) : [...form.centroIds, id])

  return (
    <Modal title={isNew ? 'Nuevo estudio' : 'Editar estudio'} label={isNew ? 'Estudios de interés' : study.nombre} onClose={onClose} section="ajustes" wide
      footer={(
        <>
          {!isNew && isAdmin && <button type="button" className="ed-btn ed-btn--danger" onClick={remove} style={{ marginRight: 'auto' }}><Icon name="trash" size={14} /> Eliminar</button>}
          <button type="button" className="ed-btn" onClick={onClose}>Cancelar</button>
          <button type="button" className="ed-btn ed-btn--solid" onClick={save} disabled={busy || !form.nombre.trim()}>{busy && <Spinner />} {isNew ? 'Crear estudio' : 'Guardar'}</button>
        </>
      )}>
      <div className="ed-stack">
        {error && <Alert tone="error">{error}</Alert>}
        {existing && (
          <Alert>
            Ya existe <strong>{existing.nombre}</strong>. Mejor usar ese para no tener el mismo estudio dos veces.
            <div style={{ marginTop: 8 }}>
              <button type="button" className="ed-btn ed-btn--sm ed-btn--solid" onClick={useExisting}>
                <Icon name="check" size={13} /> Usar «{existing.nombre}»
              </button>
            </div>
          </Alert>
        )}
        {isNew && !isAdmin && (
          <p className="ed-muted" style={{ margin: 0 }}>
            Para cursos o estudios que aún no están en la lista (p. ej. un curso corto nuevo). Administración puede revisarlo después.
          </p>
        )}
        <div className="ed-grid ed-grid--2">
          <Field label="Nombre"><input className="ed-input" value={form.nombre} onChange={set('nombre')} data-autofocus placeholder={isAdmin ? '' : 'Ej.: Curso de Procreate para ilustración'} /></Field>
          <Field label="Tipo">
            <select className="ed-select" value={form.tipo} onChange={set('tipo')}>
              {Object.entries(catalog.tipos).map(([id, label]) => <option key={id} value={id}>{label}</option>)}
            </select>
          </Field>
          <Field label="Área" hint="Sirve para reconocerlo al importar">
            <select className="ed-select" value={form.area} onChange={set('area')}>
              <option value="">Sin área</option>
              {Object.entries(catalog.areas).map(([id, label]) => <option key={id} value={id}>{label}</option>)}
            </select>
          </Field>
          {!user?.empresa && (
            <Field label="Empresa" hint="Los leads con este estudio van a esta empresa">
              <select className="ed-select" value={form.empresa} onChange={set('empresa')}>
                {COMPANIES.map((c) => <option key={c.id} value={c.id}>{c.label}</option>)}
              </select>
            </Field>
          )}
          {isAdmin && <Field label="Orden"><input className="ed-input" type="number" value={form.orden} onChange={set('orden')} /></Field>}
        </div>
        <Field label="Color">
          <div className="ed-row" style={{ gap: 6 }}>
            {PALETTE.map((color) => (
              <button key={color} type="button" onClick={() => set('color')(color)} aria-label={`Color ${color}`} aria-pressed={form.color === color}
                style={{ width: 32, height: 32, borderRadius: 999, border: '2px solid var(--ink)', background: color, cursor: 'pointer', boxShadow: form.color === color ? '0 0 0 3px var(--paper), 0 0 0 5px var(--ink)' : 'none' }} />
            ))}
            <input className="ed-input" style={{ width: 110 }} value={form.color} onChange={set('color')} aria-label="Color en hexadecimal" />
          </div>
        </Field>
        {isAdmin && catalog.centros.length > 0 && (
          <Field label="Se imparte en">
            <div className="ed-row">
              {catalog.centros.map((c) => <Checkbox key={c.id} checked={form.centroIds.includes(c.id)} onChange={() => toggleCenter(c.id)}>{c.nombre}</Checkbox>)}
            </div>
          </Field>
        )}
        {isAdmin && <Checkbox checked={form.activo} onChange={set('activo')}>Activo (si lo desactivas deja de ofrecerse, pero los leads lo conservan)</Checkbox>}

        {!isNew && isAdmin && (
          <div className="ed-panel ed-panel--bone ed-stack ed-stack--sm">
            <h3 className="ed-label">Alias al importar</h3>
            <div className="ed-row" style={{ gap: 6 }}>
              {aliases.length === 0 && <span className="ed-muted">Sin alias todavía.</span>}
              {aliases.map((a) => (
                <span key={a.id} className="ed-chip" style={{ paddingLeft: 10 }}>
                  {a.alias}
                  <button type="button" className="ed-icon-btn ed-icon-btn--sm" style={{ width: 20, height: 20, borderWidth: 1.5 }} onClick={() => removeAlias(a.id)} aria-label={`Quitar alias ${a.alias}`}>
                    <Icon name="x" size={10} />
                  </button>
                </span>
              ))}
            </div>
            <form className="ed-row" onSubmit={addAlias}>
              <input className="ed-input ed-grow" placeholder="Ej.: máster en diseño de moda" value={newAlias} onChange={(e) => setNewAlias(e.target.value)} />
              <button type="submit" className="ed-btn ed-btn--sm" disabled={!newAlias.trim()}><Icon name="plus" size={13} /> Añadir</button>
            </form>
          </div>
        )}
      </div>
    </Modal>
  )
}

function CenterModal({ center, onClose, onSaved }) {
  const toast = useToast()
  const [form, setForm] = useState({ nombre: center.nombre || '', ciudad: center.ciudad || '', provincia: center.provincia || '', activo: center.activo ?? true })
  const [error, setError] = useState('')
  const set = (key) => (e) => setForm({ ...form, [key]: e?.target ? e.target.value : e })

  const save = async () => {
    try {
      if (center.id) await catalogApi.updateCenter(center.id, form)
      else await catalogApi.createCenter(form)
      toast.ok('Centro guardado')
      onSaved()
    } catch (err) {
      setError(err.message)
    }
  }

  return (
    <Modal title={center.id ? 'Editar centro' : 'Nuevo centro'} onClose={onClose} section="ajustes"
      footer={(
        <>
          <button type="button" className="ed-btn" onClick={onClose}>Cancelar</button>
          <button type="button" className="ed-btn ed-btn--solid" onClick={save} disabled={!form.nombre.trim()}>Guardar</button>
        </>
      )}>
      <div className="ed-stack">
        {error && <Alert tone="error">{error}</Alert>}
        <Field label="Nombre"><input className="ed-input" value={form.nombre} onChange={set('nombre')} placeholder="Estación Diseño Valladolid" /></Field>
        <div className="ed-grid ed-grid--2">
          <Field label="Ciudad"><input className="ed-input" value={form.ciudad} onChange={set('ciudad')} /></Field>
          <Field label="Provincia"><input className="ed-input" value={form.provincia} onChange={set('provincia')} /></Field>
        </div>
        <Checkbox checked={form.activo} onChange={set('activo')}>Activo</Checkbox>
      </div>
    </Modal>
  )
}
