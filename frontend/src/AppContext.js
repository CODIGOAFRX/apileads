import { createContext, useContext } from 'react'

/** Usuario en sesión y catálogo (estudios y centros), compartidos por toda la app. */
export const AppContext = createContext(null)

export function useApp() {
  return useContext(AppContext)
}
