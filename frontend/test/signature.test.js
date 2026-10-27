import { test } from 'node:test'
import assert from 'node:assert/strict'
import { estacionSignature } from '../src/lib/signature.js'

test('la firma de Estación Diseño lleva los datos de la persona', () => {
  const html = estacionSignature({ nombre: 'Marta Ruiz', cargo: 'Atención al usuario', email: 'marta@example.com', telefono: '600 12 34 56' })

  assert.match(html, />Marta Ruiz</)
  assert.match(html, />Atención al usuario</)
  assert.match(html, /href="mailto:marta@example.com"/)
  assert.match(html, /href="tel:\+34600123456"[^>]*>600 12 34 56</)
  assert.match(html, /derechos ARCO/)
  assert.doesNotMatch(html, /src="http:/, 'las imágenes, siempre por https')
})

test('escapa los datos y se salta lo que falta', () => {
  const html = estacionSignature({ nombre: '<b>Ana</b> & Co', cargo: '', email: '', telefono: '' })

  assert.match(html, />&lt;b&gt;Ana&lt;\/b&gt; &amp; Co</)
  assert.doesNotMatch(html, /mailto:marta|href="tel:\+34\d{9}"[^>]*>\s*</)
  assert.doesNotMatch(html, /font-size:14px;color:#4d4d4d;margin-bottom/, 'sin cargo, sin su línea')
})
