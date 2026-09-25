import { test } from 'node:test'
import assert from 'node:assert/strict'
import { encryptionForPort, initialAccount, updateAccount } from '../src/lib/mailAccount.js'
import { formatBytes } from '../src/lib/format.js'

test('seguridad según el puerto SMTP', () => {
  assert.equal(encryptionForPort(465), 'ssl')
  assert.equal(encryptionForPort('587'), 'tls')
  assert.equal(encryptionForPort(2526), null)
})

test('buzón nuevo con los datos del servidor de la escuela', () => {
  const form = initialAccount(null, 'Veronica@EstacionDiseno.es')
  assert.equal(form.email, 'veronica@estaciondiseno.es')
  assert.equal(form.smtpHost, 'estaciondiseno.es')
  assert.equal(form.smtpPuerto, 465)
  assert.equal(form.imapPuerto, 993)
  assert.equal(initialAccount(null, 'admin').email, '', 'un usuario que no es un correo no se propone')
})

test('buzón guardado: nunca trae contraseña', () => {
  const form = initialAccount({ email: 'a@estaciondiseno.es', smtpPuerto: 587, carpetaEnviados: null, imapHost: null, imapPuerto: null })
  assert.equal(form.password, '')
  assert.equal(form.carpetaEnviados, '')
  assert.equal(form.imapPuerto, 993)
})

test('al cambiar el puerto se ajusta la seguridad', () => {
  const form = initialAccount(null)
  assert.equal(updateAccount(form, 'smtpPuerto', 587).smtpCifrado, 'tls')
  assert.equal(updateAccount({ ...form, smtpCifrado: 'none' }, 'smtpPuerto', 2526).smtpCifrado, 'none')
})

test('tamaño de ficheros', () => {
  assert.equal(formatBytes(771692), '754 KB')
  assert.equal(formatBytes(1000302 * 2), '1,9 MB')
})
