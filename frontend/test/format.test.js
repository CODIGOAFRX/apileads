import { test } from 'node:test'
import assert from 'node:assert/strict'
import { formatDateTime, formatRelative, formatRelativeTime, toLocalInput, dayKey, initials, inkOn, plural } from '../src/lib/format.js'

test('formatea en hora de Madrid', () => {
  assert.equal(formatDateTime('2026-09-15T07:00:00Z'), '15/09/2026 · 09:00')
  assert.equal(dayKey('2026-09-15T22:30:00Z'), '2026-09-16', 'las 00:30 en Madrid ya son el día siguiente')
  assert.equal(toLocalInput('2026-10-26T08:00:00Z'), '2026-10-26T09:00', 'horario de invierno')
})

test('fechas relativas', () => {
  const now = new Date('2026-09-15T10:00:00Z')
  assert.equal(formatRelative('2026-09-15T07:00:00Z', now), 'hoy 09:00')
  assert.equal(formatRelative('2026-09-14T16:02:00Z', now), 'ayer 18:02')
  assert.equal(formatRelative('2026-09-16T07:00:00Z', now), 'mañana 09:00')
  assert.equal(formatRelative('2026-09-18T07:00:00Z', now), 'viernes 09:00')
  assert.equal(formatRelative('2026-09-01T07:00:00Z', now), '01/09/2026')
  assert.equal(formatRelative(null, now), '')
})

test('utilidades de texto y color', () => {
  assert.equal(initials('lucía martín pérez'), 'LM')
  assert.equal(initials(''), '?')
  assert.equal(inkOn('#FFFF82'), '#1F201D')
  assert.equal(inkOn('#1F201D'), '#FFFFFF')
  assert.equal(plural(1, 'lead'), '1 lead')
  assert.equal(plural(3, 'intento'), '3 intentos')
})

test('llamadas agendadas: siempre con la hora', () => {
  const now = new Date('2026-09-15T10:00:00Z')
  assert.equal(formatRelativeTime('2026-09-17T15:00:00Z', now), 'jueves 17:00')
  assert.equal(formatRelativeTime('2026-10-02T15:00:00Z', now), '02/10/2026 · 17:00')
})
