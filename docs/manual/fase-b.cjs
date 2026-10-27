const L = require('./lib.cjs')
const { execSync } = require('child_process')
const buzon = (x) => execSync(`node buzon.cjs ${x}`, { cwd: __dirname })
async function openCall(page, name) {
  const r = L.row(page, name)
  await r.scrollIntoViewIfNeeded()
  await r.locator('.ed-icon-btn--call').click()
  await page.waitForSelector('.ed-popover')
  await page.waitForTimeout(500)
}
async function toast(page, name) {
  await page.waitForSelector('.ed-toast')
  await page.waitForTimeout(400)
  await L.shot(page, name, page.locator('.ed-toast').last(), 10)
}
;(async () => {
  const browser = await L.launch()
  const page = await L.open(browser, 'marta', { viewport: { width: 1280, height: 900 } })

  // Menú de llamada de Elena
  await openCall(page, 'Elena Gil Ramos')
  const pop = page.locator('.ed-popover')
  await L.mark(page, [
    [pop.locator('a.ed-btn--acc'), 1, { pos: 'l' }],
    [pop.locator('a.ed-btn--wa'), 2, { pos: 'tl' }],
    [pop.locator('button.ed-btn--mail'), 3, { pos: 'tr' }],
    [pop.locator('textarea'), 4, { pos: 'l' }],
    [pop.locator('.ed-outcome').nth(0), 5, { pos: 'l' }],
    [pop.locator('.ed-outcome').nth(1), 6, { pos: 'l' }],
    [pop.locator('.ed-outcome').nth(2), 7, { pos: 'l' }],
    [pop.locator('.ed-outcome').nth(3), 8, { pos: 'l' }],
  ])
  const box = await pop.boundingBox()
  await L.shot(page, 'menu-llamada', { x: box.x - 50, y: box.y - 20, width: box.width + 70, height: box.height + 40 })
  await L.unmark(page)

  // 1 · No contesta
  await page.keyboard.press('1')
  await toast(page, 'aviso-no-contesta')
  await page.waitForSelector('.ed-modal')
  await page.waitForTimeout(1500)
  const m = page.locator('.ed-modal')
  await L.modalShot(page, 'correo-no-contesta', () => [
    [m.locator('dl.ed-kv dd').nth(0), 1, { pos: 'l', dx: -60 }],
    [m.locator('dl.ed-kv dd').nth(3), 3, { pos: 'l', dx: -60 }],
    [m.locator('input[aria-label=Asunto]'), 2, { pos: 'l', dx: -60 }],
    [m.locator('iframe.ed-mail-preview--editable'), 4, { pos: 'l' }],
    [m.getByRole('button', { name: /Solo WhatsApp/ }), 5, { pos: 'tl' }],
    [m.getByRole('button', { name: /Enviar y abrir WhatsApp/ }), 6, { pos: 'tr' }],
  ])
  await m.locator('[data-close]').click()
  await page.waitForTimeout(400)

  // La fila en «no contestan»
  await L.goTab(page, 'no-contestan')
  await L.shot(page, 'fila-no-contesta', L.row(page, 'Elena Gil Ramos'), 6)
  await L.goTab(page, 'pendientes')

  // 2 · No interesado
  await openCall(page, 'Sofía Navarro Ortega')
  await page.keyboard.press('2')
  await toast(page, 'aviso-no-interesado')
  await page.waitForTimeout(4500)

  // 3 · Interesado (con nota) y correo retocado
  await openCall(page, 'Nerea Gil Navarro')
  await page.locator('.ed-popover textarea').fill('Quiere venir a ver la escuela')
  await page.locator('.ed-popover .ed-outcome').nth(2).click()
  await page.waitForSelector('.ed-modal')
  await page.waitForTimeout(1800)
  const frame = page.frameLocator('iframe.ed-mail-preview--editable')
  const para = frame.locator('p').nth(1)
  await para.click()
  await page.keyboard.press('End')
  await page.keyboard.type(' Te recuerdo que la matrícula de este curso cierra el viernes 6 de noviembre.')
  await page.waitForTimeout(500)
  const m2 = page.locator('.ed-modal')
  await L.modalShot(page, 'correo-informacion', () => [
    [m2.locator('dl.ed-kv'), 1, { pos: 'l' }],
    [m2.locator('iframe.ed-mail-preview--editable'), 2, { pos: 'l' }],
    [m2.getByRole('button', { name: /Deshacer cambios/ }), 3, { pos: 'tr' }],
    [m2.locator('iframe.ed-mail-preview--compact'), 4, { pos: 'l' }],
    [m2.getByRole('button', { name: /Ahora no/ }), 5, { pos: 'tl' }],
    [m2.getByRole('button', { name: /Enviar correo/ }), 6, { pos: 'tr' }],
  ])
  // Se envía sin buzón propio (en la demo no sale nada de verdad)
  buzon('off')
  await m2.getByRole('button', { name: /Enviar correo/ }).click()
  await toast(page, 'aviso-correo-enviado')
  buzon('on')
  await page.waitForTimeout(1000)

  // Cuenta atrás: otro correo enseguida
  const ruben = L.row(page, 'Rubén Serrano Gil')
  await ruben.scrollIntoViewIfNeeded()
  await ruben.locator('.ed-icon-btn--mail').click()
  await page.waitForSelector('.ed-modal')
  await page.waitForTimeout(1500)
  await L.shot(page, 'correo-espera', page.locator('.ed-modal .ed-alert--info').first(), 10)
  await L.shot(page, 'correo-espera-boton', page.locator('.ed-modal__foot'), 8)
  await page.locator('.ed-modal [data-close]').click()
  await page.waitForTimeout(400)

  // 4 · Agendar otra llamada
  await openCall(page, 'Marta Martín Molina')
  await page.locator('.ed-popover textarea').fill('Está en clase, que la llamemos a las 19:30')
  await page.locator('.ed-popover .ed-outcome').nth(3).click()
  await page.locator('.ed-popover input[type=time]').fill('19:30')
  const pop2 = page.locator('.ed-popover')
  await L.mark(page, [
    [pop2.locator('input[type=date]'), 1, { pos: 'l' }],
    [pop2.locator('input[type=time]'), 2, { pos: 'tr' }],
    [pop2.getByRole('button', { name: /Agendar llamada/ }), 3, { pos: 'tr' }],
  ])
  const b2 = await pop2.boundingBox()
  await L.shot(page, 'menu-agendar', { x: b2.x - 50, y: b2.y - 20, width: b2.width + 70, height: b2.height + 40 })
  await L.unmark(page)
  await pop2.getByRole('button', { name: /Agendar llamada/ }).click()
  await toast(page, 'aviso-agendada')
  await L.goTab(page, 'agendadas')
  await L.shot(page, 'agendadas', { x: 0, y: 120, width: 1280, height: 700 })
  await browser.close()
})().catch((e) => { console.error(e); process.exit(1) })
