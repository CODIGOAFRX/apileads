// Registro de actividad (Actividad, solo administración): agrupación y textos.

/** Grupos del filtro «acción» y los tipos que incluye cada uno. */
export const ACTIVITY_GROUPS = [
  { id: 'llamadas', label: 'Llamadas', tipos: ['LLAMADA_ABIERTA', 'MARCADO', 'LLAMADA'] },
  { id: 'whatsapp', label: 'WhatsApp', tipos: ['WHATSAPP'] },
  { id: 'correo', label: 'Correos', tipos: ['CORREO'] },
  { id: 'notas', label: 'Notas', tipos: ['NOTA'] },
  { id: 'estado', label: 'Cambios de estado', tipos: ['CAMBIO_ESTADO'] },
  { id: 'leads', label: 'Fichas y altas', tipos: ['FICHA', 'CREADO', 'IMPORTADO', 'FUSIONADO', 'EDITADO'] },
  { id: 'app', label: 'Entradas, importar, exportar y ajustes', tipos: ['ENTRADA', 'IMPORTACION', 'EXPORTACION', 'AJUSTES'] },
]

const TONES = {
  LLAMADA: 'ok', LLAMADA_ABIERTA: 'acc', MARCADO: 'acc', WHATSAPP: 'ok', CORREO: 'cyan',
  NOTA: 'warn', CAMBIO_ESTADO: 'purple', ENTRADA: 'ghost', AJUSTES: 'ghost',
}

export function toneFor(tipo) {
  return TONES[tipo] || 'ghost'
}

export function filterEvents(events, groupId) {
  const group = ACTIVITY_GROUPS.find((g) => g.id === groupId)
  return group ? events.filter((e) => group.tipos.includes(e.tipo)) : events
}

/** Día anterior o siguiente de una fecha YYYY-MM-DD (sin líos de zona horaria). */
export function shiftDay(date, days) {
  const [y, m, d] = date.split('-').map(Number)
  const next = new Date(Date.UTC(y, m - 1, d + days))
  return next.toISOString().slice(0, 10)
}

/** Cifras de la tarjeta de una comercial, solo las que no son cero. */
export function summaryLines(s) {
  const lines = [
    ['Llamadas registradas', s.llamadas, s.llamadas ? `${s.contestan} contestan · ${s.noContestan} no contestan` : ''],
    ['Abre la llamada', s.llamadasAbiertas],
    ['Pulsa el teléfono', s.marcados],
    ['WhatsApp', s.whatsapp],
    ['Correos enviados', s.correos, s.correosError ? `${s.correosError} con error` : ''],
    ['Interesados', s.interesados],
    ['Llamadas agendadas', s.agendadas],
    ['No interesados', s.noInteresados],
    ['Citas', s.citas],
    ['Matrículas', s.matriculas],
    ['Descartes', s.descartes],
    ['Notas', s.notas],
    ['Leads dados de alta', s.altas],
  ]
  return lines.filter(([, value, extra]) => value || extra).map(([label, value, extra]) => ({ label, value, extra: extra || '' }))
}
