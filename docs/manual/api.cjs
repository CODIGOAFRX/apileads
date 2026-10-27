// Llamadas a la API de la demo local para preparar los casos del manual.
const BASE = (process.env.MANUAL_URL || 'http://localhost:8093') + '/api'
const tokens = {}
const USERS = {
  marta: ['marta@estaciondiseno.es', 'DemoLocal2026'],
  nuria: ['nuria@estaciondiseno.es', 'DemoLocal2026'],
  admin: ['admin@estaciondiseno.es', 'AdminLocal2026'],
}
async function login(who) {
  const [email, password] = USERS[who]
  const r = await fetch(`${BASE}/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, password }) })
  const data = await r.json()
  tokens[who] = data.token
  return data
}
async function api(who, method, path, body) {
  if (!tokens[who]) await login(who)
  const r = await fetch(`${BASE}${path}`, {
    method,
    headers: { Authorization: `Bearer ${tokens[who]}`, ...(body ? { 'Content-Type': 'application/json' } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  })
  const text = await r.text()
  let data
  try { data = JSON.parse(text) } catch { data = text }
  if (!r.ok) throw new Error(`${method} ${path} → ${r.status}: ${text.slice(0, 300)}`)
  return data
}
async function findLead(name) {
  const res = await api('admin', 'GET', `/leads?bandeja=todos&q=${encodeURIComponent(name)}`)
  return res.items[0]
}
module.exports = { api, login, findLead, USERS }
