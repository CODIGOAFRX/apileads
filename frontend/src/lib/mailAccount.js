// Formulario del buzón de correo de un usuario (Ajustes → Usuarios → Correo).

/** Seguridad habitual de cada puerto SMTP: 465 SSL directo, 587/25 STARTTLS. */
export function encryptionForPort(port) {
  const n = Number(port)
  if (n === 465) return 'ssl'
  if (n === 587 || n === 25 || n === 2525) return 'tls'
  return null
}

/** Estado inicial: lo guardado o, si no hay, los datos del servidor de Estación Diseño (Plesk). */
export function initialAccount(saved, fallbackEmail = '') {
  if (saved) return { ...saved, password: '', carpetaEnviados: saved.carpetaEnviados ?? '', imapHost: saved.imapHost ?? '', imapPuerto: saved.imapPuerto ?? 993 }
  const email = /@estaciondiseno\.es$/i.test(fallbackEmail) ? fallbackEmail.toLowerCase() : ''
  return {
    email,
    usuario: '',
    password: '',
    smtpHost: 'estaciondiseno.es',
    smtpPuerto: 465,
    smtpCifrado: 'ssl',
    imapHost: 'estaciondiseno.es',
    imapPuerto: 993,
    guardarEnviados: true,
    carpetaEnviados: '',
    verificarCertificado: true,
  }
}

/** Cambia un campo y arrastra lo que depende de él (seguridad según el puerto). */
export function updateAccount(form, key, value) {
  const next = { ...form, [key]: value }
  if (key === 'smtpPuerto') next.smtpCifrado = encryptionForPort(value) ?? form.smtpCifrado
  return next
}
