const { api } = require('./api.cjs')
async function edit(id, patch) {
  const l = await api('admin', 'GET', `/leads/${id}`)
  const form = {
    nombre: l.nombre || '', apellidos: l.apellidos || '', email: l.email || '', telefono: l.telefono || '',
    ubicacion: l.ubicacion || '', interes: l.interes || '', estudioIds: l.estudios.map((s) => s.id),
    origen: l.origen, centroId: l.centro?.id || null, comentarios: l.comentarios || '', ...patch,
  }
  const r = await api('admin', 'PUT', `/leads/${id}`, form)
  console.log('editado', id, r.nombreCompleto, r.empresa, r.estudios.map((s) => s.nombre).join(','))
}
;(async () => {
  await edit(20, { origen: 'FERIA' })
  await edit(40, { estudioIds: [5], interes: '' })
  await edit(36, { estudioIds: [4], interes: '' })
  await edit(16, { estudioIds: [7], interes: '' })
  await edit(28, { origen: 'WEB' })
  const r = await api('marta', 'POST', '/leads', { nombre: 'Raúl', apellidos: 'Castro Molina', telefono: '600 00 64 02', email: 'raul.castro02@example.com', ubicacion: 'Sevilla', estudioIds: [14], origen: 'APRENDEMAS', comentarios: '' })
  console.log('creado', r.lead.id, r.lead.nombreCompleto, r.lead.empresa)
})().catch((e) => { console.error(e); process.exit(1) })
