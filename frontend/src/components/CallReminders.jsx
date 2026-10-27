import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { leadsApi } from '../api/endpoints.js'
import { formatTime } from '../lib/format.js'
import { activeReminders, clockOffset, dueCalls, msUntilNext, reminderKey } from '../lib/reminders.js'
import { Icon } from './Icon.jsx'

const POLL_MS = 2 * 60_000 // se vuelven a pedir las llamadas (las agendan también las compañeras)
const TICK_MS = 15_000 // red de seguridad: los navegadores retrasan los temporizadores en segundo plano
const SNOOZE_MS = 5 * 60_000
const STORE = 'apileads.avisos'

/** Avisos ya mostrados o cerrados en esta pestaña: al recargar no vuelve a sonar. */
function loadStore() {
  try {
    const data = JSON.parse(sessionStorage.getItem(STORE) || '{}')
    return { notified: new Set(data.notified || []), closed: new Set(data.closed || []), snoozed: data.snoozed || {} }
  } catch {
    return { notified: new Set(), closed: new Set(), snoozed: {} }
  }
}

function saveStore({ notified, closed, snoozed }) {
  try {
    sessionStorage.setItem(STORE, JSON.stringify({ notified: [...notified], closed: [...closed], snoozed }))
  } catch { /* almacenamiento bloqueado: solo se pierde el recuerdo al recargar */ }
}

/** Dos notas cortas, como un recordatorio de calendario. */
function chime() {
  try {
    const Context = window.AudioContext || window.webkitAudioContext
    if (!Context) return
    const ctx = new Context()
    ;[[880, 0], [1175, 0.18]].forEach(([freq, start]) => {
      const osc = ctx.createOscillator()
      const gain = ctx.createGain()
      osc.frequency.value = freq
      gain.gain.setValueAtTime(0.0001, ctx.currentTime + start)
      gain.gain.exponentialRampToValueAtTime(0.18, ctx.currentTime + start + 0.02)
      gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + start + 0.35)
      osc.connect(gain).connect(ctx.destination)
      osc.start(ctx.currentTime + start)
      osc.stop(ctx.currentTime + start + 0.4)
    })
    setTimeout(() => ctx.close().catch(() => {}), 1000)
  } catch { /* sin audio (pestaña sin interacción todavía): queda el aviso visual */ }
}

/** Pide permiso para los avisos del sistema; hay que llamarlo desde un clic. */
export function askNotificationPermission() {
  if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
    Notification.requestPermission().catch(() => {})
  }
}

/**
 * Avisa a la hora de cada llamada agendada, como un recordatorio de calendario: aviso del
 * sistema (también con la pestaña en segundo plano), tarjeta en la aplicación, sonido y
 * título parpadeando. A todas las comerciales les refresca el listado en ese momento para
 * que el lead aparezca el primero en pendientes; el aviso es para quien la agendó.
 */
export function CallReminders({ onOpen }) {
  const [calls, setCalls] = useState([])
  const [offset, setOffset] = useState(0)
  const [now, setNow] = useState(() => Date.now())
  const store = useRef(loadStore())
  const refreshed = useRef(new Set())
  const [, rerender] = useState(0)
  const [permission, setPermission] = useState(() => (typeof Notification === 'undefined' ? 'unsupported' : Notification.permission))

  const fetchCalls = useCallback(async () => {
    try {
      const data = await leadsApi.reminders()
      setOffset(clockOffset(data.ahora))
      setCalls(data.llamadas || [])
      setNow(Date.now())
    } catch { /* sin red: se reintenta en el siguiente ciclo */ }
  }, [])

  useEffect(() => {
    fetchCalls()
    const poll = setInterval(fetchCalls, POLL_MS)
    const tick = setInterval(() => setNow(Date.now()), TICK_MS)
    const onVisible = () => { if (document.visibilityState === 'visible') fetchCalls() }
    window.addEventListener('focus', fetchCalls)
    document.addEventListener('visibilitychange', onVisible)
    window.addEventListener('leads:reminders', fetchCalls)
    return () => {
      clearInterval(poll)
      clearInterval(tick)
      window.removeEventListener('focus', fetchCalls)
      document.removeEventListener('visibilitychange', onVisible)
      window.removeEventListener('leads:reminders', fetchCalls)
    }
  }, [fetchCalls])

  const serverNow = now + offset

  // Despertador exacto para la siguiente llamada (el tick de 15 s es solo por si se retrasa).
  useEffect(() => {
    const wait = msUntilNext(calls, serverNow)
    if (wait === null) return undefined
    const timer = setTimeout(() => setNow(Date.now()), Math.min(wait + 250, 2 ** 31 - 1))
    return () => clearTimeout(timer)
  }, [calls, serverNow])

  // Ha llegado la hora: el listado se actualiza (para todas) y salta el aviso (para quien la agendó).
  const active = useMemo(
    () => activeReminders(calls, serverNow, store.current.closed, store.current.snoozed),
    [calls, serverNow],
  )
  useEffect(() => {
    const due = dueCalls(calls, serverNow)
    if (due.some((call) => !refreshed.current.has(reminderKey(call)))) {
      due.forEach((call) => refreshed.current.add(reminderKey(call)))
      window.dispatchEvent(new CustomEvent('leads:refresh'))
    }
    const fresh = active.filter((call) => !store.current.notified.has(reminderKey(call)))
    if (fresh.length === 0) return
    fresh.forEach((call) => {
      store.current.notified.add(reminderKey(call))
      if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
        try {
          const notice = new Notification(`Llamada agendada · ${formatTime(call.hora)}`, {
            body: `Toca llamar a ${call.nombre}${call.telefono ? ` (${call.telefono})` : ''}`,
            tag: reminderKey(call),
            requireInteraction: true,
          })
          notice.onclick = () => { window.focus(); onOpen(call.id); notice.close() }
        } catch { /* algunos navegadores solo permiten avisos desde un service worker */ }
      }
    })
    chime()
    saveStore(store.current)
  }, [active, calls, serverNow, onOpen])

  // Título parpadeando mientras haya avisos y la pestaña no esté a la vista.
  useEffect(() => {
    if (active.length === 0) return undefined
    const original = document.title
    const alert = `🔔 Llamar a ${active[0].nombre}`
    let on = false
    const blink = setInterval(() => {
      on = document.hidden || !document.hasFocus() ? !on : false
      document.title = on ? alert : original
    }, 1000)
    return () => { clearInterval(blink); document.title = original }
  }, [active])

  const close = (call) => {
    store.current.closed.add(reminderKey(call))
    saveStore(store.current)
    rerender((n) => n + 1)
  }
  const snooze = (call) => {
    const key = reminderKey(call)
    store.current.snoozed[key] = Date.now() + offset + SNOOZE_MS
    store.current.notified.delete(key) // al volver, vuelve a sonar
    saveStore(store.current)
    rerender((n) => n + 1)
  }
  const callNow = (call) => {
    close(call)
    onOpen(call.id)
  }
  const enable = () => {
    if (typeof Notification === 'undefined') return
    Notification.requestPermission().then(setPermission).catch(() => {})
  }

  const visible = activeReminders(calls, serverNow, store.current.closed, store.current.snoozed)
  if (visible.length === 0) return null

  return (
    <div className="ed-reminders" role="region" aria-label="Llamadas agendadas">
      {visible.map((call) => (
        <div key={reminderKey(call)} className="ed-reminder" role="alert">
          <div className="ed-reminder__head">
            <span className="ed-reminder__icon"><Icon name="phone" size={18} /></span>
            <div className="ed-grow">
              <p className="ed-label" style={{ margin: 0 }}>Llamada agendada · {formatTime(call.hora)}</p>
              <p className="ed-strong ed-reminder__name">{call.nombre}</p>
              {call.telefono && <p className="ed-small ed-muted" style={{ margin: 0 }}>{call.telefono}</p>}
            </div>
            <button type="button" className="ed-icon-btn ed-icon-btn--sm" onClick={() => close(call)} aria-label={`Cerrar el aviso de ${call.nombre}`}>
              <Icon name="x" size={14} />
            </button>
          </div>
          <div className="ed-row" style={{ gap: 8 }}>
            <button type="button" className="ed-btn ed-btn--solid ed-btn--sm" onClick={() => callNow(call)}>
              <Icon name="phone" size={13} /> Llamar ahora
            </button>
            <button type="button" className="ed-btn ed-btn--sm" onClick={() => snooze(call)}>
              <Icon name="clock" size={13} /> En 5 min
            </button>
          </div>
          {permission === 'default' && (
            <button type="button" className="ed-reminder__enable" onClick={enable}>
              Activar los avisos del ordenador para enterarte aunque estés en otra pestaña
            </button>
          )}
        </div>
      ))}
    </div>
  )
}
