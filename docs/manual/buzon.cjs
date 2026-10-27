// node buzon.cjs on|off|firma
const { api } = require('./api.cjs')
const path = require('path')
const { pathToFileURL } = require('url')
;(async () => {
  const what = process.argv[2]
  if (what === 'on') {
    await api('admin', 'PUT', '/usuarios/2/correo', {
      email: 'marta@estaciondiseno.es', usuario: '', password: 'no-es-real', smtpHost: 'estaciondiseno.es', smtpPuerto: 465, smtpCifrado: 'ssl',
      imapHost: 'estaciondiseno.es', imapPuerto: 993, guardarEnviados: true, carpetaEnviados: '', verificarCertificado: true,
    })
  } else if (what === 'off') {
    await api('admin', 'DELETE', '/usuarios/2/correo')
  } else if (what === 'firma') {
    const { estacionSignature } = await import(pathToFileURL(path.resolve(__dirname, '../../frontend/src/lib/signature.js')).href)
    const firma = estacionSignature({ nombre: 'Marta Ruiz', cargo: 'Atención al usuario', email: 'marta@estaciondiseno.es', telefono: '600 00 00 00' })
    const u = (await api('admin', 'GET', '/usuarios')).find((x) => x.id === 2)
    await api('admin', 'PUT', '/usuarios/2', { nombre: u.nombre, email: u.email, rol: u.rol, empresa: u.empresa || '', activo: u.activo, password: '', firma })
  }
  console.log('buzón/firma:', what)
})().catch((e) => { console.error(e); process.exit(1) })
