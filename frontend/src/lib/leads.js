// Definiciones compartidas del flujo comercial (pestañas, estados, acciones).

export const INBOXES = [
  { id: 'pendientes', label: 'pendientes', title: 'Pendientes', hint: 'Nuevos y los que toca volver a llamar' },
  { id: 'agendadas', label: 'agendadas', title: 'Agendadas', hint: 'El día y la hora acordados vuelven a pendientes, los primeros y en verde' },
  { id: 'no-contestan', label: 'no contestan', title: 'No contestan', hint: 'Vuelven solos a pendientes el siguiente día laborable a las 9:00' },
  { id: 'interesados', label: 'interesados', title: 'Interesados', hint: 'Con interés o cita agendada' },
  { id: 'matriculados', label: 'matriculados', title: 'Matriculados', hint: 'Leads convertidos' },
  { id: 'descartados', label: 'descartados', title: 'Descartados', hint: 'No se borran: sirven para campañas de correo' },
  { id: 'historico', label: 'histórico', title: 'Histórico', hint: 'Todos los contactos, también los antiguos importados al histórico, menos los descartados' },
  { id: 'todos', label: 'todos', title: 'Todos los leads', hint: 'Búsqueda en toda la base de datos' },
]

// AGENDAR no se registra directamente: primero pide el día y la hora.
export const CALL_OUTCOMES = [
  { id: 'NO_CONTESTA', label: 'No contesta', hint: 'Te propone correo y WhatsApp · vuelve mañana a las 9:00', tone: 'warn', icon: 'clock' },
  { id: 'NO_INTERESADO', label: 'Contesta · no interesado', hint: 'Pasa a descartados (no se borra)', tone: 'busy', icon: 'ban' },
  { id: 'INTERESADO', label: 'Contesta · interesado', hint: 'Pasa a interesados', tone: 'ok', icon: 'check' },
  { id: 'AGENDAR', label: 'Agendar otra llamada', hint: 'Ese día a esa hora sale el primero, en verde', tone: 'pink', icon: 'calendar' },
]

export const STATUS_TONE = {
  NUEVO: 'acc',
  NO_CONTESTA: 'warn',
  AGENDADO: 'pink',
  INTERESADO: 'ok',
  CITA: 'ok',
  MATRICULADO: 'purple',
  DESCARTADO: 'ghost',
  HISTORICO: 'cyan',
}

export const STUDY_TYPES = [
  { id: 'GRADO', label: 'Grados' },
  { id: 'CFGS', label: 'Ciclos (CFGS)' },
  { id: 'MASTER', label: 'Másteres' },
  { id: 'CURSO', label: 'Cursos' },
]

// Las dos empresas del grupo (catalog.empresas trae sus nombres).
export const COMPANIES = [
  { id: 'ESTACION', label: 'Estación Diseño' },
  { id: 'EFP', label: 'Grupo EFP' },
]

/**
 * De qué empresa es un lead con esos estudios: de Estación Diseño si alguno lo es
 * y, si no (o sin estudio), de Grupo EFP. Igual que Company::ofStudies en el servidor.
 */
export function companyOf(studyIds, studies) {
  const estacion = (studyIds || []).some((id) => studies.find((s) => s.id === id)?.empresa === 'ESTACION')
  return estacion ? 'ESTACION' : 'EFP'
}

// Los orígenes vienen del servidor (catalog.origenes): [{ id, label, seleccionable }].

/** Los que se pueden elegir al dar de alta un lead (uno solo). */
export function selectableOrigins(origins) {
  return (origins || []).filter((o) => o.seleccionable)
}

/** Nombre de un origen; los antiguos que ya no existen salen como «Sin indicar». */
export function originLabel(origins, id) {
  if (!id) return '—'
  return (origins || []).find((o) => o.id === id)?.label ?? 'Sin indicar'
}

export const EMPTY_FILTERS = {
  q: '',
  nombre: '',
  email: '',
  telefono: '',
  ubicacion: '',
  estudio: [],
  tipo: '',
  empresa: '',
  origen: '',
  sinEstudio: false,
  desde: '',
  hasta: '',
}

/** Número de filtros avanzados activos (el buscador general no cuenta). */
export function activeFilterCount(filters) {
  return Object.entries(filters).filter(([key, value]) => {
    if (key === 'q') return false
    return Array.isArray(value) ? value.length > 0 : Boolean(value)
  }).length
}

/** Parámetros para la API a partir del estado de la pantalla. */
export function buildLeadQuery({ inbox, filters, page, perPage, sort }) {
  return {
    bandeja: inbox,
    ...filters,
    q: filters.q.trim(),
    pagina: page > 1 ? page : undefined,
    porPagina: perPage,
    orden: sort?.field && sort.field !== 'entrada' ? sort.field : undefined,
    dir: sort?.dir === 'asc' ? 'asc' : undefined,
  }
}

/**
 * Nombre propuesto al crear un estudio desde un texto importado: se quitan los
 * códigos del principio y las mayúsculas ("F250457AA | XX0001 - CURSO DE COCINA" → "Curso de cocina").
 */
export function suggestStudyName(text) {
  const words = String(text || '').split('|').pop().trim().split(/\s+/)
  while (words.length > 1 && /\d/.test(words[0])) words.shift()
  const name = words.join(' ').replace(/^[\s\-–—:·]+/, '').trim()
  return name && name === name.toUpperCase() ? name.charAt(0) + name.slice(1).toLowerCase() : name
}

export function displayName(lead) {
  return lead.nombreCompleto || lead.email || lead.telefono || 'Sin nombre'
}

/**
 * Qué se ofrece después de registrar una acción: tras «interesado», el correo;
 * tras «no contesta», el correo y a continuación WhatsApp.
 */
export function followUpAfter(outcome, lead) {
  const can = (action) => lead.acciones.includes(action)
  if (outcome === 'INTERESADO') return can('enviar-info') ? 'mail' : undefined
  if (outcome === 'NO_CONTESTA') {
    if (can('enviar-info')) return can('whatsapp') ? 'mail-whatsapp' : 'mail'
    if (can('whatsapp')) return 'whatsapp'
  }
  return undefined
}

/** Resumen corto del seguimiento para la columna de estado. */
export function followUp(lead) {
  if (lead.estado === 'NO_CONTESTA') return `${lead.intentos} ${lead.intentos === 1 ? 'intento' : 'intentos'}`
  if (lead.estado === 'DESCARTADO') return lead.motivoDescarte || ''
  if (lead.estado === 'NUEVO' && lead.intentos > 0) return `${lead.intentos} sin respuesta`
  return ''
}
