const L = require('./lib.cjs')
const { api } = require('./api.cjs')
async function search(page, text) {
  await page.fill('.ed-search input', text)
  await page.waitForTimeout(1200)
}
;(async () => {
  const browser = await L.launch()
  let page = await L.open(browser, 'marta', { viewport: { width: 1280, height: 1500 } })

  // Ficha (otra vez, recortada a su contenido)
  await L.goTab(page, 'interesados')
  await L.row(page, 'Nerea Gil Navarro').locator('.ed-lead__name').click()
  await page.waitForSelector('.ed-drawer .ed-timeline')
  await page.waitForTimeout(600)
  const d = page.locator('.ed-drawer')
  await L.mark(page, [
    [d.locator('.ed-drawer__head .ed-grow'), 1, { pos: 'l', dx: 8 }],
    [d.locator('.ed-drawer__body > .ed-row').first(), 2, { pos: 'l', dx: 8 }],
    [d.locator('section.ed-panel'), 3, { pos: 'l', dx: 8 }],
    [d.locator('section.ed-drawer__section').nth(1), 4, { pos: 'l', dx: 8 }],
    [d.locator('section.ed-drawer__section').nth(2), 5, { pos: 'l', dx: 8 }],
  ])
  const db = await d.boundingBox()
  const end = await d.locator('.ed-timeline').evaluate((e) => e.getBoundingClientRect().bottom)
  await L.shot(page, 'ficha', { x: db.x - 30, y: 0, width: db.width + 30, height: end + 30 })
  await L.unmark(page)
  await d.locator('[data-close]').click()
  await page.waitForTimeout(400)
  await page.setViewportSize({ width: 1280, height: 900 })

  // Buscador en «todos»
  await L.goTab(page, 'todos')
  await search(page, 'granada')
  await L.mark(page, [['.ed-search', 1, { pos: 'l' }], ['.ed-hero__count', 2, { pos: 'tl' }]])
  await L.shot(page, 'buscar-granada', { x: 0, y: 120, width: 1280, height: 640 })
  await L.unmark(page)
  await search(page, '9119')
  await L.shot(page, 'buscar-telefono', { x: 0, y: 400, width: 1280, height: 330 })
  await search(page, 'moda')
  await L.shot(page, 'buscar-estudio', { x: 0, y: 400, width: 1280, height: 480 })
  await search(page, '')

  // Filtros avanzados
  await page.getByRole('button', { name: /Filtros/ }).click()
  await page.waitForTimeout(300)
  await page.locator('.ed-panel--bone .ed-studypick button', { hasText: 'Máster Ilustración' }).first().click()
  await page.waitForTimeout(1200)
  const panel = page.locator('.ed-panel--bone')
  const pb = await panel.boundingBox()
  await L.shot(page, 'filtros', { x: 0, y: pb.y - 110, width: 1280, height: pb.height + 330, full: true })
  await page.getByRole('button', { name: /Limpiar todo/ }).click()
  await page.getByRole('button', { name: /Filtros/ }).click()
  await page.waitForTimeout(300)

  // Nuevo lead
  await L.goTab(page, 'pendientes')
  await page.getByRole('button', { name: /Nuevo lead/ }).click()
  await page.waitForSelector('.ed-modal')
  const m = page.locator('.ed-modal')
  const f = (label) => m.locator('.ed-field', { has: page.locator('label', { hasText: new RegExp('^' + label + '$') }) }).locator('input, textarea').first()
  await f('Nombre').fill('Laura')
  await f('Apellidos').fill('Castillo Ortega')
  await f('Teléfono').fill('611 22 33 44')
  await f('Correo').fill('laura.castillo@example.com')
  await f('Ubicación').fill('Granada')
  await m.locator('[role=radiogroup] button', { hasText: 'Redes sociales' }).click()
  await m.locator('.ed-studypick:not(.ed-studypick--plain) button', { hasText: 'Grado Diseño Gráfico' }).click()
  await f('Comentarios').fill('Ha llamado a recepción. Nos vio en Instagram.')
  await L.modalShot(page, 'nuevo-lead', () => [
    [m.locator('.ed-grid--2').first(), 1, { pos: 'l' }],
    [m.locator('[role=radiogroup]'), 2, { pos: 'l' }],
    [m.locator('.ed-studypick:not(.ed-studypick--plain)'), 3, { pos: 'l' }],
    [m.getByText('Este lead es de').locator('..'), 4, { pos: 'l' }],
    [m.getByRole('button', { name: /crear estudio/ }), 5, { pos: 'r' }],
    [m.locator('textarea'), 6, { pos: 'l' }],
    [m.getByRole('button', { name: /Crear lead/ }), 7, { pos: 'tr' }],
  ])
  await m.locator('[data-close]').click()
  await page.waitForTimeout(400)

  // Duplicado: Elena vuelve a llamar
  await page.getByRole('button', { name: /Nuevo lead/ }).click()
  await page.waitForSelector('.ed-modal')
  await f('Nombre').fill('Elena')
  await f('Apellidos').fill('Gil')
  await f('Teléfono').fill('600 00 91 19')
  await m.locator('[role=radiogroup] button', { hasText: 'Feria' }).click()
  await m.getByRole('button', { name: /Crear lead/ }).click()
  await page.waitForSelector('.ed-modal .ed-alert')
  await page.waitForTimeout(400)
  const alert = m.locator('.ed-alert').first()
  await L.mark(page, [
    [alert.getByRole('button', { name: /Abrir su ficha/ }), 1, { pos: 'b', box: false }],
    [alert.getByRole('button', { name: /Añadir estos datos/ }), 2, { pos: 'b', box: false }],
  ])
  await L.shot(page, 'duplicado', alert, { x: 10, y: 10, b: 34 })
  await L.unmark(page)
  await m.locator('[data-close]').click()
  await page.waitForTimeout(400)

  // Una compañera está llamando
  await api('nuria', 'POST', '/leads/37/bloqueo')
  await page.reload()
  await page.waitForSelector('.ed-lead')
  await L.hideReminders(page)
  const diego = L.row(page, 'Diego Domínguez Ruiz')
  await L.shot(page, 'fila-llamando', diego, 6)
  await diego.locator('.ed-icon-btn--call').click()
  await page.waitForSelector('.ed-popover .ed-alert')
  await page.waitForTimeout(500)
  await L.shot(page, 'menu-bloqueado', page.locator('.ed-popover'), 8)
  await page.keyboard.press('Escape')
  await api('nuria', 'DELETE', '/leads/37/bloqueo')
  await page.context().close()

  // Móvil
  page = await L.open(browser, 'marta', { viewport: { width: 390, height: 844 }, scale: 3, mobile: true })
  await page.waitForTimeout(800)
  await L.shot(page, 'movil', null)
  await browser.close()
})().catch((e) => { console.error(e); process.exit(1) })
