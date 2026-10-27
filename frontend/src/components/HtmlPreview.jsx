import { useRef } from 'react'

function documentFor(html) {
  return `<!doctype html><html><head><meta charset="utf-8"><style>
    body{font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1F201D;margin:16px}
    body[contenteditable]{outline:none;min-height:calc(100vh - 32px);cursor:text}
    a{color:#1F201D}</style></head><body>${html}</body></html>`
}

/** Muestra HTML de correo aislado en un iframe sin scripts. «compact»: más bajo (p. ej. una firma). */
export function HtmlPreview({ html, title = 'Vista previa del correo', compact = false }) {
  return <iframe className={`ed-mail-preview${compact ? ' ed-mail-preview--compact' : ''}`} title={title} sandbox="" srcDoc={documentFor(html)} />
}

/**
 * El mismo correo, pero se puede escribir en él. Sigue sin ejecutar scripts
 * (sandbox sin allow-scripts); allow-same-origin solo sirve para leer lo escrito.
 * El HTML inicial se carga una vez: para volver al original, cambia la «key».
 */
export function EditableHtml({ html, onChange, title = 'Correo (se puede modificar)' }) {
  const onChangeRef = useRef(onChange)
  onChangeRef.current = onChange
  const initial = useRef(documentFor(html))

  const onLoad = (event) => {
    const body = event.currentTarget.contentDocument?.body
    if (!body) return
    body.contentEditable = 'true'
    body.addEventListener('input', () => onChangeRef.current(body.innerHTML))
  }

  return (
    <iframe className="ed-mail-preview ed-mail-preview--editable" title={title} sandbox="allow-same-origin"
      srcDoc={initial.current} onLoad={onLoad} />
  )
}
