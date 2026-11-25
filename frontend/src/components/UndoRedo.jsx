import { useCallback, useEffect, useRef, useState } from 'react'
import { undoApi } from '../api/endpoints.js'
import { isTyping, shortcutOf, undoLabel } from '../lib/undo.js'
import { Icon } from './Icon.jsx'
import { useToast } from './Toasts.jsx'

const EMPTY = { deshacer: null, rehacer: null }

/** Deshace (o rehace) en el servidor y avisa al listado y a los avisos de llamada. */
export async function travel(redo = false) {
  const result = await (redo ? undoApi.redo() : undoApi.undo())
  window.dispatchEvent(new CustomEvent('leads:refresh'))
  window.dispatchEvent(new CustomEvent('leads:reminders'))
  return result
}

/**
 * Botones de deshacer y rehacer de la cabecera, también con Ctrl+Z y Ctrl+Y
 * (o Ctrl+Mayús+Z). Valen para cualquier cambio de un lead: resultado de llamada,
 * agendar o cancelar, interesado, cita, matrícula, descarte, reapertura o edición.
 */
export function UndoRedo() {
  const toast = useToast()
  const [state, setState] = useState(EMPTY)
  const [busy, setBusy] = useState(false)
  const busyRef = useRef(false)
  const stateRef = useRef(EMPTY)
  stateRef.current = state

  const refresh = useCallback(() => {
    undoApi.state().then((next) => setState(next || EMPTY)).catch(() => {})
  }, [])

  useEffect(() => {
    refresh()
    window.addEventListener('api:changed', refresh)
    window.addEventListener('focus', refresh)
    return () => {
      window.removeEventListener('api:changed', refresh)
      window.removeEventListener('focus', refresh)
    }
  }, [refresh])

  const go = useCallback(async (redo) => {
    if (busyRef.current || !(redo ? stateRef.current.rehacer : stateRef.current.deshacer)) return
    busyRef.current = true
    setBusy(true)
    try {
      const result = await travel(redo)
      setState({ deshacer: result.deshacer, rehacer: result.rehacer })
      toast.ok(`${redo ? 'Rehecho' : 'Deshecho'}: ${result.descripcion} (${result.nombre})`)
    } catch (err) {
      toast.error(err)
      refresh()
    } finally {
      busyRef.current = false
      setBusy(false)
    }
  }, [toast, refresh])

  // Atajos: no mientras se escribe en un campo ni con un diálogo abierto.
  useEffect(() => {
    const onKey = (event) => {
      const action = shortcutOf(event)
      if (!action || isTyping(event.target) || document.querySelector('[role="dialog"]')) return
      event.preventDefault()
      go(action === 'redo')
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [go])

  return (
    <div className="ed-undo" role="group" aria-label="Deshacer y rehacer">
      <button type="button" className="ed-icon-btn" onClick={() => go(false)} disabled={busy || !state.deshacer}
        title={state.deshacer ? `${undoLabel(state.deshacer)} · Ctrl+Z` : undoLabel(null)} aria-label={undoLabel(state.deshacer)}>
        <Icon name="undo" />
      </button>
      <button type="button" className="ed-icon-btn" onClick={() => go(true)} disabled={busy || !state.rehacer}
        title={state.rehacer ? `${undoLabel(state.rehacer, true)} · Ctrl+Y` : undoLabel(null, true)} aria-label={undoLabel(state.rehacer, true)}>
        <Icon name="redo" />
      </button>
    </div>
  )
}
