const L = require('./lib.cjs')
;(async () => {
  const browser = await L.launch()
  // Buscador (recortes buenos)
  let page = await L.open(browser, 'marta', { viewport: { width: 1280, height: 1000 } })
  await L.goTab(page, 'todos')
  for (const [q, name, h] of [['9119', 'buscar-telefono', 330], ['moda', 'buscar-estudio', 560]]) {
    await page.fill('.ed-search input', q)
    await page.waitForTimeout(1300)
    const b = await page.locator('.ed-search').boundingBox()
    await L.shot(page, name, { x: 0, y: b.y - 14, width: 1280, height: h })
  }
  await page.context().close()

  page = await L.open(browser, 'admin', { viewport: { width: 1280, height: 1000 } })
  await L.shot(page, 'menu-admin', page.locator('.ed-header__nav'), 10)
  await page.getByRole('button', { name: 'Ajustes', exact: true }).click()
  await page.waitForTimeout(500)
  // Usuarios
  await page.getByRole('tab', { name: 'Usuarios' }).click()
  await page.waitForSelector('.ed-table')
  // Nuria solo trabaja Estación Diseño (para que se vea la columna)
  await page.locator('tr', { hasText: 'Nuria Prado' }).getByRole('button', { name: /Editar Nuria/ }).click()
  await page.locator('.ed-modal select').nth(1).selectOption('ESTACION')
  await L.modalShot(page, 'usuario-editar')
  await page.locator('.ed-modal').getByRole('button', { name: 'Guardar' }).click()
  await page.waitForTimeout(900)
  const table = page.locator('.ed-table-wrap')
  await L.mark(page, [
    [table.locator('th', { hasText: 'Envía desde' }), 1, { pos: 'tl' }],
    [table.locator('th', { hasText: 'Leads de' }), 2, { pos: 'tl' }],
    [table.locator('tr', { hasText: 'Marta Ruiz' }).getByRole('button', { name: /Correo de envío/ }), 3, { pos: 'b', box: false }],
    [table.locator('tr', { hasText: 'Marta Ruiz' }).getByRole('button', { name: /Firma/ }), 4, { pos: 'b', box: false }],
    [table.locator('tr', { hasText: 'Marta Ruiz' }).getByRole('button', { name: /Editar/ }), 5, { pos: 'b', box: false }],
  ])
  const tb = await table.boundingBox()
  await L.shot(page, 'usuarios', { x: 0, y: tb.y - 90, width: 1280, height: tb.height + 130 })
  await L.unmark(page)
  // Buzón de Marta
  await page.locator('tr', { hasText: 'Marta Ruiz' }).getByRole('button', { name: /Correo de envío/ }).click()
  await page.waitForSelector('.ed-modal input[type=email]')
  await L.modalShot(page, 'buzon')
  await page.locator('.ed-modal [data-close]').click()
  await page.waitForTimeout(300)
  // Firma de Marta
  await page.locator('tr', { hasText: 'Marta Ruiz' }).getByRole('button', { name: /Firma/ }).click()
  await page.waitForSelector('.ed-modal')
  await page.locator('.ed-modal input').nth(1).fill('Atención al usuario')
  await page.locator('.ed-modal input').nth(3).fill('600 00 00 00')
  await page.waitForTimeout(1500)
  await L.modalShot(page, 'firma')
  await page.locator('.ed-modal [data-close]').click()
  await page.waitForTimeout(300)

  // Estudios y centros
  await page.getByRole('tab', { name: 'Estudios y centros' }).click()
  await page.waitForTimeout(500)
  await L.shot(page, 'estudios-admin', { x: 0, y: 230, width: 1280, height: 640 })
  await page.getByRole('button', { name: 'Editar Grado Diseño Moda' }).click()
  await L.modalShot(page, 'estudio-editar')
  await page.locator('.ed-modal [data-close]').click()
  await page.waitForTimeout(300)

  // Correos
  await page.getByRole('tab', { name: 'Correos' }).click()
  await page.waitForTimeout(600)
  await page.setViewportSize({ width: 1280, height: 2200 })
  await page.locator('.ed-split button', { hasText: 'Grado Diseño Gráfico' }).click()
  await page.waitForTimeout(1200)
  const split = page.locator('.ed-split')
  const panel = split.locator('.ed-panel').first()
  await L.mark(page, [
    [split.locator('.ed-stack--sm').first(), 1, { pos: 'tl' }],
    [panel.locator('.ed-field').first(), 2, { pos: 'tr' }],
    [panel.locator('textarea'), 3, { pos: 'tr' }],
    [panel.locator('.ed-label', { hasText: 'Dossier adjunto' }).locator('..'), 4, { pos: 'tr' }],
    [panel.locator('.ed-label', { hasText: 'Variables disponibles' }).locator('..'), 5, { pos: 'tr' }],
  ])
  const sb = await split.boundingBox()
  const vb = await panel.locator('.ed-label', { hasText: 'Variables disponibles' }).locator('..').boundingBox()
  await L.shot(page, 'correos', { x: 0, y: sb.y - 20, width: 1280, height: vb.y + vb.height - sb.y + 40 })
  await L.unmark(page)
  await page.setViewportSize({ width: 1280, height: 1000 })

  // Actividad
  await page.getByRole('button', { name: 'Actividad', exact: true }).click()
  await page.waitForSelector('.ed-table, .ed-empty')
  await page.waitForTimeout(800)
  await page.setViewportSize({ width: 1280, height: 1400 })
  await page.waitForTimeout(300)
  await L.mark(page, [
    [page.locator('.ed-toolbar .ed-row').first(), 1, { pos: 'l' }],
    [page.locator('.ed-toolbar .ed-row').nth(1), 2, { pos: 'tr' }],
    [page.locator('.ed-grid--3 .ed-panel').first(), 3, { pos: 'tl' }],
    [page.locator('.ed-table-wrap'), 4, { pos: 'tl', box: false }],
  ])
  await L.shot(page, 'actividad', { x: 0, y: 120, width: 1280, height: 1250 })
  await browser.close()
})().catch((e) => { console.error(e); process.exit(1) })
