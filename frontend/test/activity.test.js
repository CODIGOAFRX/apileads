import { test } from 'node:test'
import assert from 'node:assert/strict'
import { filterEvents, shiftDay, summaryLines } from '../src/lib/activity.js'

test('cambiar de día, también entre meses y años', () => {
  assert.equal(shiftDay('2026-09-30', 1), '2026-10-01')
  assert.equal(shiftDay('2026-01-01', -1), '2025-12-31')
  assert.equal(shiftDay('2026-03-29', 1), '2026-03-30', 'el cambio de hora no afecta')
})

test('filtrar por grupo de acciones', () => {
  const events = [{ tipo: 'LLAMADA' }, { tipo: 'MARCADO' }, { tipo: 'WHATSAPP' }, { tipo: 'ENTRADA' }]
  assert.deepEqual(filterEvents(events, 'llamadas').map((e) => e.tipo), ['LLAMADA', 'MARCADO'])
  assert.equal(filterEvents(events, '').length, 4)
})

test('la tarjeta solo muestra lo que ha hecho', () => {
  const lines = summaryLines({ llamadas: 3, contestan: 1, noContestan: 2, whatsapp: 0, correos: 0, correosError: 1, notas: 2 })
  assert.deepEqual(lines.map((l) => l.label), ['Llamadas registradas', 'Correos enviados', 'Notas'])
  assert.equal(lines[0].extra, '1 contestan · 2 no contestan')
  assert.equal(lines[1].extra, '1 con error')
})
