// Todas las llamadas a la API en un único sitio.
import { http } from './http.js'

export const authApi = {
  login: (email, password) => http.post('/auth/login', { email, password }),
  me: () => http.get('/auth/me'),
  changePassword: (actual, nueva) => http.post('/auth/password', { actual, nueva }),
}

export const leadsApi = {
  list: (query) => http.get('/leads', query),
  counters: () => http.get('/leads/contadores'),
  get: (id) => http.get(`/leads/${id}`),
  create: (data) => http.post('/leads', data),
  update: (id, data) => http.put(`/leads/${id}`, data),
  lock: (id) => http.post(`/leads/${id}/bloqueo`),
  unlock: (id) => http.delete(`/leads/${id}/bloqueo`),
  call: (id, resultado, nota) => http.post(`/leads/${id}/llamada`, { resultado, nota }),
  scheduleCall: (id, fecha, nota) => http.post(`/leads/${id}/llamada-agendada`, { fecha, nota }),
  interested: (id) => http.post(`/leads/${id}/interesado`),
  appointment: (id, fecha, nota) => http.post(`/leads/${id}/cita`, { fecha, nota }),
  enroll: (id, nota) => http.post(`/leads/${id}/matricula`, { nota }),
  discard: (id, motivo) => http.post(`/leads/${id}/descarte`, { motivo }),
  reopen: (id) => http.post(`/leads/${id}/reapertura`),
  note: (id, texto) => http.post(`/leads/${id}/notas`, { texto }),
  whatsapp: (id) => http.post(`/leads/${id}/whatsapp`),
  mailPreview: (id) => http.get(`/leads/${id}/correo`),
  // asunto y html solo si la comercial ha retocado este correo.
  sendMail: (id, asunto, html) => http.post(`/leads/${id}/correo`, { asunto, html }),
  export: (query) => http.download('/leads/exportar', query, 'leads.csv'),
}

export const importApi = {
  preview: (file, opciones) => http.upload('/importaciones/previsualizar', formData(file, opciones)),
  run: (file, opciones) => http.upload('/importaciones', formData(file, opciones)),
  history: () => http.get('/importaciones'),
  template: (formato) => http.download('/importaciones/plantilla', { formato }, `plantilla-leads.${formato}`),
}

export const catalogApi = {
  get: () => http.get('/catalogo'),
  createStudy: (data) => http.post('/estudios', data),
  updateStudy: (id, data) => http.put(`/estudios/${id}`, data),
  deleteStudy: (id) => http.delete(`/estudios/${id}`),
  addAlias: (id, alias) => http.post(`/estudios/${id}/alias`, { alias }),
  deleteAlias: (id) => http.delete(`/alias/${id}`),
  createCenter: (data) => http.post('/centros', data),
  updateCenter: (id, data) => http.put(`/centros/${id}`, data),
}

export const mailApi = {
  templates: () => http.get('/plantillas'),
  save: (estudioId, asunto, cuerpoHtml, clave = null) => http.put('/plantillas', { estudioId, asunto, cuerpoHtml, clave }),
  remove: (estudioId) => http.delete(`/plantillas/${estudioId}`),
}

export const usersApi = {
  list: () => http.get('/usuarios'),
  create: (data) => http.post('/usuarios', data),
  update: (id, data) => http.put(`/usuarios/${id}`, data),
}

function formData(file, opciones) {
  const data = new FormData()
  data.append('archivo', file)
  data.append('opciones', JSON.stringify(opciones ?? {}))
  return data
}
