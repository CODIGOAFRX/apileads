// Formatos de fecha y texto en hora de Madrid, independientemente del ordenador.
const ZONE = 'Europe/Madrid'

const dayFormatter = new Intl.DateTimeFormat('es-ES', { timeZone: ZONE, day: '2-digit', month: '2-digit', year: 'numeric' })
const timeFormatter = new Intl.DateTimeFormat('es-ES', { timeZone: ZONE, hour: '2-digit', minute: '2-digit' })
const weekdayFormatter = new Intl.DateTimeFormat('es-ES', { timeZone: ZONE, weekday: 'long' })
const keyFormatter = new Intl.DateTimeFormat('en-CA', { timeZone: ZONE, year: 'numeric', month: '2-digit', day: '2-digit' })

function toDate(value) {
  if (!value) return null
  const date = value instanceof Date ? value : new Date(value)
  return Number.isNaN(date.getTime()) ? null : date
}

/** Clave YYYY-MM-DD del día en Madrid. */
export function dayKey(value) {
  const date = toDate(value)
  return date ? keyFormatter.format(date) : ''
}

export function formatDate(value) {
  const date = toDate(value)
  return date ? dayFormatter.format(date) : ''
}

export function formatTime(value) {
  const date = toDate(value)
  return date ? timeFormatter.format(date) : ''
}

export function formatDateTime(value) {
  const date = toDate(value)
  return date ? `${dayFormatter.format(date)} · ${timeFormatter.format(date)}` : ''
}

/** "hoy 10:24", "ayer 18:02", "lunes 09:00", "12/09/2026". */
export function formatRelative(value, now = new Date()) {
  const date = toDate(value)
  if (!date) return ''
  const days = Math.round((Date.parse(dayKey(date)) - Date.parse(dayKey(now))) / 86400000)
  const time = timeFormatter.format(date)
  if (days === 0) return `hoy ${time}`
  if (days === -1) return `ayer ${time}`
  if (days === 1) return `mañana ${time}`
  if (days > 1 && days < 7) return `${weekdayFormatter.format(date)} ${time}`
  return dayFormatter.format(date)
}

/** Como formatRelative pero siempre con la hora (llamadas agendadas): "jueves 17:00", "02/10/2026 · 17:00". */
export function formatRelativeTime(value, now = new Date()) {
  const text = formatRelative(value, now)
  return /\d{2}:\d{2}$/.test(text) ? text : formatDateTime(value)
}

/** Valor para <input type="datetime-local"> en hora de Madrid. */
export function toLocalInput(value) {
  const date = toDate(value)
  if (!date) return ''
  return `${dayKey(date)}T${timeFormatter.format(date)}`
}

export function plural(count, singular, pluralForm = `${singular}s`) {
  return `${count} ${count === 1 ? singular : pluralForm}`
}

export function initials(name) {
  const parts = String(name || '').trim().split(/\s+/).filter(Boolean)
  if (!parts.length) return '?'
  return (parts[0][0] + (parts[1]?.[0] ?? '')).toUpperCase()
}

/** Color de texto legible sobre un color de fondo (#RRGGBB). */
export function inkOn(hex) {
  const match = /^#?([0-9a-f]{6})$/i.exec(hex || '')
  if (!match) return '#1F201D'
  const n = parseInt(match[1], 16)
  const [r, g, b] = [(n >> 16) & 255, (n >> 8) & 255, n & 255]
  return (0.299 * r + 0.587 * g + 0.114 * b) > 140 ? '#1F201D' : '#FFFFFF'
}

/** "850 KB", "1,2 MB". */
export function formatBytes(bytes) {
  const value = Number(bytes) || 0
  if (value < 1024 * 1024) return `${Math.max(1, Math.round(value / 1024))} KB`
  return `${(value / 1024 / 1024).toLocaleString('es-ES', { maximumFractionDigits: 1 })} MB`
}
