import { useEffect, useRef } from 'react'
import { createPortal } from 'react-dom'
import { Icon } from './Icon.jsx'
import { cn } from '../lib/cn.js'

let openDialogs = 0

/**
 * Cierra con Escape (solo el diálogo de más arriba), bloquea el scroll de la
 * página y devuelve el foco al elemento que abrió el diálogo.
 */
function useDialog(onClose) {
  const ref = useRef(null)
  const closeRef = useRef(onClose)
  closeRef.current = onClose

  useEffect(() => {
    const previous = document.activeElement
    const onKey = (event) => {
      if (event.key !== 'Escape') return
      const dialogs = document.querySelectorAll('[role="dialog"]')
      if (dialogs[dialogs.length - 1] === ref.current) closeRef.current()
    }
    document.addEventListener('keydown', onKey)
    openDialogs += 1
    document.body.style.overflow = 'hidden'
    const target = ref.current?.querySelector('[data-autofocus]')
      ?? ref.current?.querySelector('input, select, textarea, button:not([data-close])')
    target?.focus()
    return () => {
      document.removeEventListener('keydown', onKey)
      openDialogs -= 1
      if (openDialogs === 0) document.body.style.overflow = ''
      previous?.focus?.()
    }
  }, [])
  return ref
}

export function Modal({ title, label, onClose, children, footer, wide = false, section }) {
  const ref = useDialog(onClose)
  return createPortal(
    <div className="ed-overlay" onMouseDown={(e) => e.target === e.currentTarget && onClose()} data-section={section}>
      <div ref={ref} className={cn('ed-modal', wide && 'ed-modal--wide')} role="dialog" aria-modal="true" aria-label={title}>
        <div className="ed-modal__head">
          <div className="ed-grow">
            {label && <p className="ed-label ed-label--dark">{label}</p>}
            <h2 className="ed-display ed-modal__title">{title}</h2>
          </div>
          <button type="button" className="ed-icon-btn" onClick={onClose} aria-label="Cerrar" data-close>
            <Icon name="x" />
          </button>
        </div>
        <div className="ed-modal__body">{children}</div>
        {footer && <div className="ed-modal__foot">{footer}</div>}
      </div>
    </div>,
    document.body,
  )
}

export function Drawer({ onClose, header, children, section }) {
  const ref = useDialog(onClose)
  return createPortal(
    <div className="ed-overlay ed-drawer-overlay" onMouseDown={(e) => e.target === e.currentTarget && onClose()} data-section={section}>
      <aside ref={ref} className="ed-drawer" role="dialog" aria-modal="true">
        <div className="ed-drawer__head">
          <div className="ed-row ed-row--between" style={{ alignItems: 'flex-start', flexWrap: 'nowrap' }}>
            <div className="ed-grow">{header}</div>
            <button type="button" className="ed-icon-btn" onClick={onClose} aria-label="Cerrar ficha" data-close>
              <Icon name="x" />
            </button>
          </div>
        </div>
        <div className="ed-drawer__body">{children}</div>
      </aside>
    </div>,
    document.body,
  )
}
