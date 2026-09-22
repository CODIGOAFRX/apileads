import { test } from 'node:test'
import assert from 'node:assert/strict'
import { EMPTY_FILTERS, activeFilterCount, buildLeadQuery, displayName, followUp, followUpAfter, suggestStudyName } from '../src/lib/leads.js'
import { toQuery } from '../src/api/http.js'

test('construye la consulta del buscador sin parámetros vacíos', () => {
  const filters = { ...EMPTY_FILTERS, q: '  andres ', estudio: [5, 7], tipo: 'MASTER' }
  const query = buildLeadQuery({ inbox: 'pendientes', filters, page: 1, perPage: 50, sort: { field: 'entrada', dir: 'desc' } })

  assert.equal(toQuery(query), '?bandeja=pendientes&q=andres&estudio=5%2C7&tipo=MASTER&porPagina=50')
})

test('incluye página, orden y booleanos', () => {
  const filters = { ...EMPTY_FILTERS, sinEstudio: true }
  const query = buildLeadQuery({ inbox: 'todos', filters, page: 3, perPage: 50, sort: { field: 'nombre', dir: 'asc' } })

  assert.equal(toQuery(query), '?bandeja=todos&sinEstudio=1&pagina=3&porPagina=50&orden=nombre&dir=asc')
})

test('cuenta solo los filtros avanzados activos', () => {
  assert.equal(activeFilterCount({ ...EMPTY_FILTERS, q: 'x' }), 0)
  assert.equal(activeFilterCount({ ...EMPTY_FILTERS, estudio: [1], origen: 'WEB', sinEstudio: true }), 3)
})

test('nombre visible y seguimiento', () => {
  assert.equal(displayName({ nombreCompleto: '', email: 'a@b.es' }), 'a@b.es')
  assert.equal(displayName({ nombreCompleto: '', email: null, telefono: null }), 'Sin nombre')
  assert.equal(followUp({ estado: 'NO_CONTESTA', intentos: 1 }), '1 intento')
  assert.equal(followUp({ estado: 'NO_CONTESTA', intentos: 4 }), '4 intentos')
  assert.equal(followUp({ estado: 'DESCARTADO', motivoDescarte: 'No interesado' }), 'No interesado')
  assert.equal(followUp({ estado: 'INTERESADO', intentos: 0 }), '')
})

test('tras no contesta se propone el correo y luego WhatsApp', () => {
  const all = { acciones: ['llamar', 'whatsapp', 'enviar-info', 'interesado', 'descartar'] }
  assert.equal(followUpAfter('NO_CONTESTA', all), 'mail-whatsapp')
  assert.equal(followUpAfter('NO_CONTESTA', { acciones: ['llamar', 'whatsapp', 'descartar'] }), 'whatsapp', 'sin correo')
  assert.equal(followUpAfter('NO_CONTESTA', { acciones: ['reabrir'] }), undefined, 'descartado tras el último intento')
  assert.equal(followUpAfter('INTERESADO', all), 'mail')
  assert.equal(followUpAfter('INTERESADO', { acciones: ['llamar', 'whatsapp'] }), undefined)
  assert.equal(followUpAfter('NO_INTERESADO', all), undefined)
})

test('nombre propuesto para un estudio nuevo', () => {
  assert.equal(suggestStudyName('F250457AA | XX0001 - CURSO DE COCINA'), 'Curso de cocina')
  assert.equal(suggestStudyName('2025/18/47/EDD1/0270 MF1002_2 Inglés profesional'), 'Inglés profesional')
  assert.equal(suggestStudyName('Máster en Fotografía'), 'Máster en Fotografía')
})
