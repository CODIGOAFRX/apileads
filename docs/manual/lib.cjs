// Utilidades para las capturas del manual.
const { chromium } = require('./pw.cjs')
const OUT = __dirname + '/capturas/'
const URL = process.env.MANUAL_URL || 'http://localhost:8093'
const USERS = {
  marta: ['marta@estaciondiseno.es', 'DemoLocal2026'],
  nuria: ['nuria@estaciondiseno.es', 'DemoLocal2026'],
  admin: ['admin@estaciondiseno.es', 'AdminLocal2026'],
}

async function launch() {
  // CHROMIUM_PATH: un Chromium ya instalado si el de esta versión de Playwright no está descargado.
  return chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {})
}

async function open(browser, who, opts = {}) {
  const context = await browser.newContext({
    viewport: opts.viewport || { width: 1280, height: 860 },
    deviceScaleFactor: opts.scale || 2,
    isMobile: !!opts.mobile,
    hasTouch: !!opts.mobile,
    locale: 'es-ES',
    timezoneId: 'Europe/Madrid',
  })
  await context.grantPermissions([], { origin: URL })
  const page = await context.newPage()
  await page.goto(URL)
  if (who) {
    const [email, password] = USERS[who]
    await page.fill('#login-email', email)
    await page.fill('#login-password', password)
    await page.click('button[type=submit]')
    await page.waitForSelector('.ed-tabs')
    await page.waitForSelector('.ed-lead, .ed-empty')
  }
  if (!opts.reminders) await hideReminders(page)
  return page
}

async function hideReminders(page) {
  await page.addStyleTag({ content: '.ed-reminders{display:none!important}' }).catch(() => {})
}

/**
 * Marca elementos con un recuadro rosa y un número, como en el manual.
 * items: [selector | Locator, n, { pos: 'tl'|'tr'|'l'|'r'|'b', pad, box }]
 */
async function mark(page, items) {
  await unmark(page)
  for (const [target, n, o = {}] of items) {
    const loc = typeof target === 'string' ? page.locator(target).first() : target
    const box = await loc.boundingBox()
    if (!box) throw new Error('No encuentro ' + target)
    await page.evaluate(({ box, n, o }) => {
      const sx = window.scrollX, sy = window.scrollY
      const pad = o.pad ?? 4
      if (o.box !== false) {
        const r = document.createElement('div')
        r.className = 'mk'
        Object.assign(r.style, {
          position: 'absolute', left: box.x + sx - pad + 'px', top: box.y + sy - pad + 'px',
          width: box.width + pad * 2 + 'px', height: box.height + pad * 2 + 'px',
          border: '2.5px solid #FF2EE6', borderRadius: '8px', zIndex: 99998, pointerEvents: 'none',
        })
        document.body.appendChild(r)
      }
      const b = document.createElement('div')
      b.className = 'mk'
      b.textContent = n
      const size = 24
      let x = box.x + sx - pad - size / 2, y = box.y + sy - pad - size / 2
      const pos = o.pos || 'tl'
      if (pos === 'tr') x = box.x + sx + box.width + pad - size / 2
      if (pos === 'l') { x = box.x + sx - pad - size - 4; y = box.y + sy + box.height / 2 - size / 2 }
      if (pos === 'r') { x = box.x + sx + box.width + pad + 4; y = box.y + sy + box.height / 2 - size / 2 }
      if (pos === 'b') { x = box.x + sx + box.width / 2 - size / 2; y = box.y + sy + box.height + pad + 2 }
      if (o.dx) x += o.dx
      if (o.dy) y += o.dy
      Object.assign(b.style, {
        position: 'absolute', left: x + 'px', top: y + 'px', width: size + 'px', height: size + 'px',
        borderRadius: '50%', background: '#FF79F5', border: '2px solid #1F201D', color: '#1F201D',
        font: '700 13px/20px Helvetica Neue, Arial, sans-serif', textAlign: 'center', zIndex: 99999,
        pointerEvents: 'none', boxSizing: 'border-box',
      })
      document.body.appendChild(b)
    }, { box, n, o })
  }
}

async function unmark(page) {
  await page.evaluate(() => document.querySelectorAll('.mk').forEach((e) => e.remove()))
}

/** Captura: clip {x,y,width,height} en coordenadas de la página, o un selector/Locator con margen. */
async function shot(page, name, what, margin = 0) {
  const path = OUT + name + '.png'
  if (!what) {
    await page.screenshot({ path })
  } else if (what.width) {
    await page.screenshot({ path, clip: what, fullPage: !!what.full })
  } else {
    const loc = typeof what === 'string' ? page.locator(what).first() : what
    await loc.scrollIntoViewIfNeeded()
    const box = await loc.boundingBox()
    const vp = page.viewportSize()
    const mx = typeof margin === 'number' ? margin : margin.x, my = typeof margin === 'number' ? margin : margin.y
    const myb = typeof margin === 'number' ? margin : (margin.b ?? margin.y)
    if (box.y - my >= 0 && box.y + box.height + myb <= vp.height) {
      const x = Math.max(0, box.x - mx), y = box.y - my
      await page.screenshot({ path, clip: { x, y, width: Math.min(vp.width - x, box.width + mx * 2), height: box.height + my + myb } })
    } else {
      const sx = await page.evaluate(() => window.scrollX), sy = await page.evaluate(() => window.scrollY)
      const x = Math.max(0, box.x + sx - mx), y = Math.max(0, box.y + sy - my)
      await page.screenshot({ path, fullPage: true, clip: { x, y, width: box.width + mx * 2, height: box.y + sy - y + box.height + myb } })
    }
  }
  console.log('captura', name)
}

/** Captura de una ventana entera: agranda la pantalla si no cabe. */
async function modalShot(page, name, marks = null, sel = '.ed-modal', margin = 14) {
  await page.waitForTimeout(450)
  const style = await page.addStyleTag({ content: '.ed-toasts{display:none!important} .ed-mail-preview--editable{height:300px!important;min-height:0!important} .ed-mail-preview--compact{height:150px!important}' })
  await page.waitForTimeout(200)
  const vp = page.viewportSize()
  const h = await page.locator(sel).last().evaluate((e) => e.getBoundingClientRect().height)
  if (h + 120 > vp.height) {
    await page.setViewportSize({ width: vp.width, height: Math.ceil(h + 140) })
    await page.waitForTimeout(300)
  }
  if (marks) await mark(page, marks())
  await shot(page, name, page.locator(sel).last(), margin)
  await unmark(page)
  await style.evaluate((e) => e.remove())
  if (h + 120 > vp.height) await page.setViewportSize(vp)
}

const row = (page, name) => page.locator(`article.ed-lead[aria-label="${name}"]`)
const tab = (page, id) => page.locator(`.ed-tab[data-tab="${id}"]`)
async function goTab(page, id) {
  await tab(page, id).click()
  await page.waitForTimeout(700)
}
const wait = (ms) => new Promise((r) => setTimeout(r, ms))

module.exports = { modalShot, launch, open, mark, unmark, shot, row, tab, goTab, wait, hideReminders, URL }
