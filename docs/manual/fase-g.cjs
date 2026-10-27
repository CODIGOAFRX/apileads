const L = require('./lib.cjs')
;(async () => {
  const browser = await L.launch()
  const page = await L.open(browser, 'admin', { viewport: { width: 1280, height: 1000 } })
  await page.getByRole('button', { name: 'Ajustes', exact: true }).click()
  await page.waitForTimeout(500)
  await page.addStyleTag({ content: '.ed-btn[data-on="true"] .ed-chip{color:var(--ink)}' })
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

  await browser.close()
})().catch((e) => { console.error(e); process.exit(1) })
