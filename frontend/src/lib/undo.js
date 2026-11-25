// Deshacer y rehacer: atajos de teclado y textos de los botones.
// La pila vive en el servidor (cada usuario, la suya); aquí solo se decide qué pulsar.

/** Ctrl+Z (o ⌘Z) deshace; Ctrl+Y, Ctrl+Mayús+Z (o ⌘⇧Z) rehace. null si no es ninguno. */
export function shortcutOf(event) {
  if (!(event.ctrlKey || event.metaKey) || event.altKey) return null
  const key = String(event.key || '').toLowerCase()
  if (key === 'z') return event.shiftKey ? 'redo' : 'undo'
  if (key === 'y' && !event.shiftKey) return 'redo'
  return null
}

/** Escribiendo en un campo: Ctrl+Z es del navegador (deshace el texto), no de la aplicación. */
export function isTyping(target) {
  if (!target || typeof target !== 'object') return false
  if (target.isContentEditable) return true
  const tag = String(target.tagName || '').toUpperCase()
  if (tag === 'TEXTAREA' || tag === 'SELECT') return true
  if (tag !== 'INPUT') return false
  return !['button', 'checkbox', 'radio', 'submit', 'reset', 'file', 'range', 'color'].includes(String(target.type || 'text').toLowerCase())
}

/** Texto del botón: qué se va a deshacer o rehacer, y de quién. */
export function undoLabel(entry, redo = false) {
  if (!entry) return redo ? 'Nada que rehacer' : 'Nada que deshacer'
  return `${redo ? 'Rehacer' : 'Deshacer'}: ${entry.descripcion} (${entry.nombre})`
}
