import { test } from 'node:test'
import assert from 'node:assert/strict'
import { isTyping, shortcutOf, undoLabel } from '../src/lib/undo.js'

test('Ctrl+Z deshace; Ctrl+Y y Ctrl+Mayús+Z rehacen (también con ⌘ en Mac)', () => {
  assert.equal(shortcutOf({ ctrlKey: true, key: 'z' }), 'undo')
  assert.equal(shortcutOf({ metaKey: true, key: 'z' }), 'undo')
  assert.equal(shortcutOf({ ctrlKey: true, shiftKey: true, key: 'Z' }), 'redo')
  assert.equal(shortcutOf({ ctrlKey: true, key: 'y' }), 'redo')
  assert.equal(shortcutOf({ key: 'z' }), null)
  assert.equal(shortcutOf({ ctrlKey: true, altKey: true, key: 'z' }), null, 'AltGr en teclados españoles')
  assert.equal(shortcutOf({ ctrlKey: true, key: 'c' }), null)
})

test('escribiendo en un campo, Ctrl+Z es del texto y no de la aplicación', () => {
  assert.equal(isTyping({ tagName: 'INPUT', type: 'text' }), true)
  assert.equal(isTyping({ tagName: 'input' }), true)
  assert.equal(isTyping({ tagName: 'TEXTAREA' }), true)
  assert.equal(isTyping({ tagName: 'DIV', isContentEditable: true }), true)
  assert.equal(isTyping({ tagName: 'INPUT', type: 'checkbox' }), false)
  assert.equal(isTyping({ tagName: 'BUTTON' }), false)
  assert.equal(isTyping(null), false)
})

test('el botón dice qué se va a deshacer y de quién', () => {
  const entry = { descripcion: 'Llamada agendada cancelada', nombre: 'Eva López' }
  assert.equal(undoLabel(entry), 'Deshacer: Llamada agendada cancelada (Eva López)')
  assert.equal(undoLabel(entry, true), 'Rehacer: Llamada agendada cancelada (Eva López)')
  assert.equal(undoLabel(null), 'Nada que deshacer')
  assert.equal(undoLabel(null, true), 'Nada que rehacer')
})
