const L = require('./lib.cjs')
const { execSync } = require('child_process')
const DB = process.env.MANUAL_DB || 'manual.sqlite'
const sql = (q) => execSync(`python -c "import sqlite3,sys;c=sqlite3.connect(sys.argv[1]);c.execute(sys.argv[2]);c.commit()" "${DB}" "${q}"`, { cwd: __dirname })
async function openFicha(page, name) {
  const r = L.row(page, name)
  await r.scrollIntoViewIfNeeded()
  await r.locator('.ed-lead__name').click()
  await page.waitForSelector('.ed-drawer .ed-timeline')
  await page.waitForTimeout(600)
}
;(async () => {
  const browser = await L.launch()
  // Aviso de llamada agendada (Irene, agendada por Marta, llega ahora)
  const now = new Date(Date.now() - 20_000).toISOString().replace('T', ' ').slice(0, 19)
  sql(`update leads set llamada_agendada='${now}', agendada_por=2 where id=16`)
  let page = await L.open(browser, 'marta', { viewport: { width: 1280, height: 860 }, reminders: true })
  await page.waitForSelector('.ed-reminder', { timeout: 20000 })
  await page.waitForTimeout(800)
  await L.shot(page, 'aviso-llamada', page.locator('.ed-reminders'), 12)
  await L.shot(page, 'aviso-llamada-pantalla', { x: 0, y: 260, width: 1280, height: 600 })
  await page.context().close()

  page = await L.open(browser, 'marta', { viewport: { width: 1280, height: 1500 } })
  // Ficha de Nerea (interesada)
  await L.goTab(page, 'interesados')
  await openFicha(page, 'Nerea Gil Navarro')
  const d = page.locator('.ed-drawer')
  await L.mark(page, [
    [d.locator('.ed-drawer__head .ed-grow'), 1, { pos: 'l', dx: 8 }],
    [d.locator('.ed-drawer__body > .ed-row').first(), 2, { pos: 'l', dx: 8 }],
    [d.locator('section.ed-panel'), 3, { pos: 'l', dx: 8 }],
    [d.locator('section.ed-drawer__section').nth(1), 4, { pos: 'l', dx: 8 }],
    [d.locator('section.ed-drawer__section').nth(2), 5, { pos: 'l', dx: 8 }],
  ])
  const db = await d.boundingBox()
  await L.shot(page, 'ficha', { x: db.x - 30, y: 0, width: db.width + 30, height: Math.min(1500, db.height) })
  await L.unmark(page)

  // Agendar cita
  await d.getByRole('button', { name: /Agendar cita/ }).click()
  await page.fill('.ed-modal input[type=datetime-local]', '2026-11-03T10:00')
  await page.fill('.ed-modal textarea', 'Viene con su madre a ver la escuela')
  await L.modalShot(page, 'cita')
  await page.locator('.ed-modal').getByRole('button', { name: /Guardar cita/ }).click()
  await page.waitForTimeout(800)
  await d.locator('[data-close]').click()
  await page.waitForTimeout(600)
  await L.shot(page, 'fila-cita', L.row(page, 'Nerea Gil Navarro'), 6)

  // Matriculado
  await openFicha(page, 'Alba Morales Castro')
  await d.getByRole('button', { name: /Matriculado/ }).click()
  await L.modalShot(page, 'matricula')
  await page.locator('.ed-modal').getByRole('button', { name: /Sí, matriculado/ }).click()
  await page.waitForSelector('.ed-toast')
  await page.waitForTimeout(300)
  await L.shot(page, 'aviso-matricula', page.locator('.ed-toast').last(), 10)
  await d.locator('[data-close]').click()
  await page.waitForTimeout(500)

  // Descartar con motivo (Pablo)
  await L.goTab(page, 'pendientes')
  await openFicha(page, 'Pablo Ramos Vázquez')
  await d.getByRole('button', { name: /Descartar/ }).click()
  await page.locator('.ed-modal').getByRole('button', { name: 'Ya está estudiando en otro centro' }).click()
  await L.modalShot(page, 'descartar')
  await page.locator('.ed-modal .ed-modal__foot').getByRole('button', { name: /Descartar/ }).click()
  await page.waitForTimeout(700)
  await d.locator('[data-close]').click()
  await page.waitForTimeout(500)
  await L.goTab(page, 'descartados')
  await L.shot(page, 'fila-descartado', L.row(page, 'Pablo Ramos Vázquez'), 6)
  await browser.close()
})().catch((e) => { console.error(e); process.exit(1) })
