const L = require('./lib.cjs')
;(async () => {
  const browser = await L.launch()
  // Entrada
  let page = await L.open(browser, null, { viewport: { width: 1280, height: 760 } })
  await page.fill('#login-email', 'marta@estaciondiseno.es')
  await page.fill('#login-password', 'DemoLocal2026')
  await L.mark(page, [['#login-email', 1, { pos: 'l' }], ['#login-password', 2, { pos: 'l' }], ['.ed-check', 3, { pos: 'l' }], ['button[type=submit]', 4, { pos: 'l' }]])
  await L.shot(page, 'login', { x: 640, y: 150, width: 640, height: 480 })
  await page.context().close()

  // Pantalla principal
  page = await L.open(browser, 'marta', { viewport: { width: 1280, height: 900 } })
  await L.mark(page, [
    ['.ed-header__nav', 1, { pos: 'l' }],
    ['.ed-tabs', 2, { pos: 'l', pad: 3 }],
    ['.ed-hero', 3, { pos: 'tr' }],
    ['.ed-search', 4, { pos: 'l' }],
    [page.getByRole('button', { name: /Filtros/ }), 5, { pos: 'tr' }],
    [page.locator('.ed-label', { hasText: 'Rápido:' }).locator('..'), 6, { pos: 'l' }],
    ['#lead-sort', 7, { pos: 'tr' }],
    [page.getByRole('button', { name: /Exportar CSV/ }), 8, { pos: 'tl' }],
    [page.getByRole('button', { name: /Nuevo lead/ }), 9, { pos: 'tr' }],
    ['.ed-list', 10, { pos: 'l', box: false, dy: 120 }],
  ])
  await L.shot(page, 'principal', { x: 0, y: 0, width: 1280, height: 900 })
  await L.unmark(page)

  // Una fila (Elena)
  const elena = L.row(page, 'Elena Gil Ramos')
  await elena.scrollIntoViewIfNeeded()
  const parts = [
    ['.ed-lead__entry', 1, { pos: 'tl' }],
    ['.ed-lead__cell:nth-child(2)', 2, { pos: 'tl' }],
    ['.ed-lead__contact', 3, { pos: 'tl' }],
    ['.ed-lead__studies', 4, { pos: 'tl' }],
    ['.ed-lead__status', 5, { pos: 'tl' }],
    ['.ed-icon-btn--wa', 6, { pos: 'b', box: false }],
    ['.ed-icon-btn--mail', 7, { pos: 'b', box: false }],
    ['.ed-icon-btn--ok', 8, { pos: 'b', box: false }],
    ['[title="Ver ficha"]', 9, { pos: 'b', box: false }],
    ['.ed-icon-btn--call', 10, { pos: 'b', box: false }],
  ]
  await L.mark(page, parts.map(([s, n, o]) => [elena.locator(s).first(), n, o]))
  await L.shot(page, 'fila', elena, { x: 34, y: 18, b: 34 })
  await L.unmark(page)

  // Fila verde (llamada agendada que ya toca)
  await L.shot(page, 'fila-agendada', L.row(page, 'Irene García Gil'), 6)
  // Fila de un lead de Grupo EFP sin estudio reconocido
  await L.shot(page, 'fila-efp', L.row(page, 'Raúl Castro Molina'), 6)
  await L.unmark(page)

  // Pestañas
  await L.shot(page, 'pestanas', '.ed-tabs', 6)

  // Mi cuenta
  await page.getByRole('button', { name: 'Ajustes' }).click()
  await page.waitForTimeout(500)
  await L.shot(page, 'mi-cuenta', { x: 0, y: 0, width: 1280, height: 640 })
  // Estudios (vista de comercial)
  await page.getByRole('tab', { name: 'Estudios' }).click()
  await page.waitForTimeout(400)
  await L.shot(page, 'estudios-comercial', { x: 0, y: 150, width: 1280, height: 700 })
  await page.getByRole('button', { name: 'Nuevo estudio' }).click()
  await page.fill('input[placeholder^="Ej.: Curso de Procreate"]', 'Curso de Procreate para ilustración')
  await page.waitForTimeout(300)
  await L.modalShot(page, 'estudio-nuevo-comercial')
  await browser.close()
})().catch((e) => { console.error(e); process.exit(1) })
