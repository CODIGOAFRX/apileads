// Se ejecuta antes de cada fichero de pruebas de componentes (vitest + jsdom).
import { afterEach } from 'vitest'
import { cleanup } from '@testing-library/react'

afterEach(() => {
  cleanup()
  sessionStorage.clear()
  localStorage.clear()
})
