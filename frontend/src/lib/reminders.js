// Avisos de llamadas agendadas: qué toca avisar y cuándo volver a mirar.
// El servidor manda su hora («ahora») para corregir un reloj del ordenador desajustado.

/** Clave de un aviso: la misma llamada reagendada a otra hora vuelve a avisar. */
export const reminderKey = (call) => `${call.id}@${call.hora}`

/** Diferencia (ms) entre la hora del servidor y la del ordenador. */
export function clockOffset(serverNow, localNow = Date.now()) {
  const server = Date.parse(serverNow)
  return Number.isNaN(server) ? 0 : server - localNow
}

/** Llamadas cuya hora ya ha llegado. */
export function dueCalls(calls, now) {
  return calls.filter((call) => Date.parse(call.hora) <= now)
}

/** Milisegundos hasta la siguiente llamada (null si no hay ninguna por llegar). */
export function msUntilNext(calls, now) {
  const next = calls.map((call) => Date.parse(call.hora)).filter((t) => t > now).sort((a, b) => a - b)[0]
  return next === undefined ? null : next - now
}

/**
 * Avisos que hay que mostrar ahora: llamadas propias ya llegadas, sin cerrar y que no
 * estén pospuestas. «closed»: claves cerradas; «snoozed»: clave → hora hasta la que se pospone.
 */
export function activeReminders(calls, now, closed, snoozed) {
  return dueCalls(calls, now).filter((call) => {
    const key = reminderKey(call)
    return call.mia && !closed.has(key) && !((snoozed[key] ?? 0) > now)
  })
}
