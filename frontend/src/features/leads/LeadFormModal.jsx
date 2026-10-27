import { useState } from 'react'
import { leadsApi } from '../../api/endpoints.js'
import { useApp } from '../../AppContext.js'
import { Icon } from '../../components/Icon.jsx'
import { Modal } from '../../components/Modal.jsx'
import { useToast } from '../../components/Toasts.jsx'
import { Alert, ChoicePicker, CompanyTag, Field, Spinner, StudyPicker } from '../../components/ui.jsx'
import { COMPANIES, companyOf, displayName, selectableOrigins } from '../../lib/leads.js'
import { StudyModal } from '../settings/CatalogSettings.jsx'

const EMPTY = {
  nombre: '', apellidos: '', email: '', telefono: '', ubicacion: '', interes: '',
  estudioIds: [], origen: '', centroId: '', comentarios: '',
}

function fromLead(lead) {
  return {
    nombre: lead.nombre || '',
    apellidos: lead.apellidos || '',
    email: lead.email || '',
    telefono: lead.telefono || '',
    ubicacion: lead.ubicacion || '',
    interes: lead.interes || '',
    estudioIds: (lead.estudios || []).map((s) => s.id),
    origen: lead.origen,
    centroId: lead.centro?.id || '',
    comentarios: lead.comentarios || '',
  }
}

/** Alta manual y edición. Si el correo o el teléfono ya existen, ofrece abrir o completar el existente. */
export function LeadFormModal({ lead, section, onClose, onSaved, onOpenExisting }) {
  const { catalog, reloadCatalog, user } = useApp()
  const toast = useToast()
  const editing = Boolean(lead)
  const [form, setForm] = useState(() => (editing ? fromLead(lead) : EMPTY))
  const [error, setError] = useState('')
  const [duplicate, setDuplicate] = useState(null)
  const [busy, setBusy] = useState(false)
  const [newStudy, setNewStudy] = useState(false)
  const set = (key) => (event) => setForm({ ...form, [key]: event?.target ? event.target.value : event })

  // El estudio recién creado queda ya marcado en el lead.
  const studyCreated = async (study) => {
    setNewStudy(false)
    await reloadCatalog().catch(() => {})
    // Nuevo o ya existente (si lo había), queda marcado en el lead, sin repetirlo.
    setForm((current) => ({ ...current, estudioIds: [...new Set([...current.estudioIds, study.id])] }))
  }

  const hasContact = form.nombre.trim() || form.apellidos.trim() || form.email.trim() || form.telefono.trim()

  const company = companyOf(form.estudioIds, catalog.estudios)

  const save = async (merge = false) => {
    if (!form.origen) {
      setError('Elige de dónde viene el lead (origen)')
      return
    }
    // Con Estación Diseño hace falta uno de sus estudios (sin estudio el lead sería de Grupo EFP).
    if (user?.empresa && company !== user.empresa) {
      setError(`Elige al menos un estudio: con tu usuario solo se dan de alta leads de ${COMPANIES.find((c) => c.id === user.empresa)?.label}`)
      return
    }
    setBusy(true)
    setError('')
    try {
      const payload = { ...form, centroId: form.centroId || null, fusionar: merge }
      const result = editing ? await leadsApi.update(lead.id, payload) : await leadsApi.create(payload)
      const saved = editing ? result : result.lead
      toast.ok(editing ? 'Cambios guardados' : merge ? 'Datos añadidos al lead existente' : 'Lead creado')
      onSaved(saved)
    } catch (err) {
      if (err.status === 409 && err.details?.duplicado) {
        setDuplicate(err.details.duplicado)
      } else {
        setError(err.message)
      }
      setBusy(false)
    }
  }

  // Solo los estudios de su empresa (el servidor ya los filtra; esto cubre un catálogo cargado antes del cambio).
  const activeStudies = catalog.estudios.filter((s) => (s.activo || form.estudioIds.includes(s.id)) && (!user?.empresa || s.empresa === user.empresa))
  const centers = catalog.centros.filter((c) => c.activo || c.id === form.centroId)

  return (
    <Modal
      title={editing ? 'Editar lead' : 'Nuevo lead'}
      label={editing ? displayName(lead) : 'Alta manual'}
      onClose={onClose}
      section={section}
      wide
      footer={(
        <>
          <button type="button" className="ed-btn" onClick={onClose}>Cancelar</button>
          <button type="button" className="ed-btn ed-btn--solid" onClick={() => save(false)} disabled={busy || !hasContact}>
            {busy ? <Spinner /> : <Icon name="check" size={14} />} {editing ? 'Guardar cambios' : 'Crear lead'}
          </button>
        </>
      )}
    >
      <form className="ed-stack" onSubmit={(e) => { e.preventDefault(); if (hasContact) save(false) }}>
        {error && <Alert tone="error">{error}</Alert>}
        {duplicate && (
          <Alert>
            <strong>Ya existe {displayName(duplicate)}</strong> ({[duplicate.telefono, duplicate.email].filter(Boolean).join(' · ')}),
            en «{duplicate.estadoLabel}».
            <div className="ed-row" style={{ marginTop: 10 }}>
              {onOpenExisting && (
                <button type="button" className="ed-btn ed-btn--sm" onClick={() => onOpenExisting(duplicate.id)}>
                  <Icon name="eye" size={13} /> Abrir su ficha
                </button>
              )}
              {!editing && (
                <button type="button" className="ed-btn ed-btn--sm ed-btn--solid" onClick={() => save(true)} disabled={busy}>
                  <Icon name="plus" size={13} /> Añadir estos datos al existente
                </button>
              )}
            </div>
          </Alert>
        )}

        <div className="ed-grid ed-grid--2">
          <Field label="Nombre"><input className="ed-input" value={form.nombre} onChange={set('nombre')} data-autofocus autoComplete="off" /></Field>
          <Field label="Apellidos"><input className="ed-input" value={form.apellidos} onChange={set('apellidos')} autoComplete="off" /></Field>
          <Field label="Teléfono" hint="Sin prefijo se entiende que es español (+34)">
            <input className="ed-input" type="tel" value={form.telefono} onChange={set('telefono')} placeholder="600 12 34 56" autoComplete="off" />
          </Field>
          <Field label="Correo"><input className="ed-input" type="email" value={form.email} onChange={set('email')} autoComplete="off" /></Field>
          <Field label="Ubicación"><input className="ed-input" value={form.ubicacion} onChange={set('ubicacion')} placeholder="Ciudad o provincia" /></Field>
          {centers.length > 1 && (
            <Field label="Centro">
              <select className="ed-select" value={form.centroId} onChange={set('centroId')}>
                <option value="">Sin asignar</option>
                {centers.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
              </select>
            </Field>
          )}
        </div>

        <Field label="Origen (elige uno)" hint={form.origen === 'OTRO' ? 'Lead antiguo sin origen: puedes dejarlo así o elegir uno' : undefined}>
          <ChoicePicker label="Origen" options={selectableOrigins(catalog.origenes)} value={form.origen}
            onChange={(origen) => { setForm({ ...form, origen }); setError('') }} />
        </Field>

        <Field label="Estudios de interés">
          <StudyPicker studies={activeStudies} value={form.estudioIds} onChange={set('estudioIds')} />
          {/* Quien trabaja con una sola empresa ya solo ve sus estudios: no hace falta decirle de cuál es. */}
          {!user?.empresa && form.estudioIds.length > 0 && (
            <div className="ed-row" style={{ gap: 8 }}>
              <span className="ed-hint">Este lead es de</span>
              <CompanyTag company={company} />
            </div>
          )}
          <div>
            <button type="button" className="ed-btn ed-btn--sm" onClick={() => setNewStudy(true)}>
              <Icon name="plus" size={13} /> No está en la lista: crear estudio
            </button>
          </div>
        </Field>
        {newStudy && <StudyModal study={{}} onClose={() => setNewStudy(false)} onSaved={studyCreated} />}
        {editing && form.interes && (
          <Field label="Interés tal como llegó" hint="Texto original del formulario o del fichero importado">
            <input className="ed-input" value={form.interes} onChange={set('interes')} />
          </Field>
        )}
        <Field label="Comentarios">
          <textarea className="ed-textarea" value={form.comentarios} onChange={set('comentarios')} />
        </Field>
        {!hasContact && <p className="ed-hint">Indica al menos un nombre, un teléfono o un correo.</p>}
        <button type="submit" hidden />
      </form>
    </Modal>
  )
}
