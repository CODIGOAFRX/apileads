import { test } from 'node:test'
import assert from 'node:assert/strict'
import { activeReminders, clockOffset, dueCalls, msUntilNext, reminderKey } from '../src/lib/reminders.js'

const at = (iso) => Date.parse(iso)
const calls = [
  { id: 1, nombre: 'Eva', hora: '2026-09-15T10:30:00Z', mia: true },
  { id: 2, nombre: 'Luis', hora: '2026-09-15T11:00:00Z', mia: false },
  { id: 3, nombre: 'Ana', hora: '2026-09-15T12:00:00Z', mia: true },
]

test('corrige un reloj del ordenador desajustado con la hora del servidor', () => {
  assert.equal(clockOffset('2026-09-15T10:00:00Z', at('2026-09-15T09:58:00Z')), 120_000)
  assert.equal(clockOffset('no es una fecha', 0), 0)
})

test('sabe qué llamadas han llegado y cuánto falta para la siguiente', () => {
  const now = at('2026-09-15T10:45:00Z')
  assert.deepEqual(dueCalls(calls, now).map((c) => c.id), [1])
  assert.equal(msUntilNext(calls, now), 15 * 60_000)
  assert.equal(msUntilNext(calls, at('2026-09-15T13:00:00Z')), null)
})

test('avisa solo de las propias, ni cerradas ni pospuestas', () => {
  const now = at('2026-09-15T12:05:00Z')
  assert.deepEqual(activeReminders(calls, now, new Set(), {}).map((c) => c.id), [1, 3], 'la de Luis la agendó otra persona')
  assert.deepEqual(activeReminders(calls, now, new Set([reminderKey(calls[0])]), {}).map((c) => c.id), [3])
  assert.deepEqual(activeReminders(calls, now, new Set(), { [reminderKey(calls[2])]: now + 60_000 }).map((c) => c.id), [1])
  assert.equal(reminderKey(calls[0]), '1@2026-09-15T10:30:00Z', 'reagendada a otra hora, vuelve a avisar')
})
