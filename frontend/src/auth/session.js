// Sesión guardada en el navegador. "Recordarme" usa localStorage; si no, dura lo que la pestaña.
const KEY = 'apileads.session'

function stores() {
  const list = []
  try { list.push(window.localStorage) } catch { /* bloqueado */ }
  try { list.push(window.sessionStorage) } catch { /* bloqueado */ }
  return list
}

export function loadSession() {
  for (const store of stores()) {
    try {
      const raw = store.getItem(KEY)
      if (!raw) continue
      const session = JSON.parse(raw)
      if (session?.token && session.expiresAt > Date.now()) return session
      store.removeItem(KEY)
    } catch { /* ignorar */ }
  }
  return null
}

export function saveSession({ token, expiraEn, usuario }, remember) {
  const session = { token, usuario, expiresAt: Date.now() + expiraEn * 1000 }
  clearSession()
  const [local, tab] = stores()
  try { (remember ? local : tab ?? local)?.setItem(KEY, JSON.stringify(session)) } catch { /* ignorar */ }
  return session
}

export function clearSession() {
  for (const store of stores()) {
    try { store.removeItem(KEY) } catch { /* ignorar */ }
  }
}
