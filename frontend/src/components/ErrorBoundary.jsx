import { Component } from 'react'
import { Icon } from './Icon.jsx'

/**
 * Red de seguridad: si una pantalla falla al pintarse, se muestra un aviso con
 * «Reintentar» en lugar de dejar la aplicación en blanco. El resto (cabecera,
 * menú) sigue funcionando.
 */
export class ErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { error: null }
  }

  static getDerivedStateFromError(error) {
    return { error }
  }

  componentDidCatch(error, info) {
    console.error('[leads] Error en la pantalla:', error, info?.componentStack)
  }

  render() {
    if (!this.state.error) return this.props.children
    return (
      <div className="ed-empty" role="alert">
        <strong>Algo ha fallado en esta pantalla</strong>
        <span>No se ha perdido nada de lo guardado. Prueba otra vez y, si se repite, recarga la página.</span>
        <div className="ed-row" style={{ justifyContent: 'center', marginTop: 12 }}>
          <button type="button" className="ed-btn" onClick={() => this.setState({ error: null })}>
            <Icon name="refresh" size={14} /> Reintentar
          </button>
          <button type="button" className="ed-btn ed-btn--solid" onClick={() => window.location.reload()}>Recargar la página</button>
        </div>
      </div>
    )
  }
}
