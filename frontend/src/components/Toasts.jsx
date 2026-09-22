import { createContext, useCallback, useContext, useMemo, useState } from 'react'
import { Icon } from './Icon.jsx'

const ToastContext = createContext(null)

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([])

  const dismiss = useCallback((id) => setToasts((list) => list.filter((t) => t.id !== id)), [])

  const push = useCallback((type, message) => {
    const id = Math.random().toString(36).slice(2)
    setToasts((list) => [...list.slice(-3), { id, type, message }])
    setTimeout(() => dismiss(id), type === 'error' ? 7000 : 3500)
  }, [dismiss])

  const api = useMemo(() => ({
    ok: (message) => push('ok', message),
    info: (message) => push('info', message),
    error: (error) => push('error', typeof error === 'string' ? error : error?.message || 'Algo ha fallado'),
  }), [push])

  return (
    <ToastContext.Provider value={api}>
      {children}
      <div className="ed-toasts" role="status" aria-live="polite">
        {toasts.map((toast) => (
          <div key={toast.id} className={`ed-toast ed-toast--${toast.type}`}>
            <Icon name={toast.type === 'error' ? 'alert' : 'check'} />
            <span>{toast.message}</span>
            <button type="button" className="ed-icon-btn ed-icon-btn--sm" onClick={() => dismiss(toast.id)} aria-label="Cerrar aviso">
              <Icon name="x" size={14} />
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

export function useToast() {
  return useContext(ToastContext)
}
