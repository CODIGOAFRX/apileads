const { api } = require('./api.cjs')
;(async () => {
  const r = await api('admin', 'GET', '/leads?bandeja=todos&porPagina=100')
  for (const l of r.items) console.log(l.id, l.bandeja.padEnd(12), l.estado.padEnd(11), l.empresa.padEnd(8), (l.nombreCompleto||'').padEnd(26), (l.telefono||'-').padEnd(16), (l.estudios.map(s=>s.nombre).join(',')||('['+(l.interes||'')+']')), '|', l.origenLabel, l.ubicacion, l.llamadaAgendada||'')
})()
