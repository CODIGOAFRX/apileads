// Monta manual.html desde manual.src.html y genera los PDF (portada y cuerpo).
// node build.cjs [paginas.json]   (desde docs/manual; ver README.md)
const fs = require('fs')
const path = require('path')
const { pathToFileURL } = require('url')
const L = require('./lib.cjs')
const REPO = path.resolve(__dirname, '../..')
const FONTS = pathToFileURL(REPO + '/frontend/public/fonts').href
const LOGO = pathToFileURL(REPO + '/frontend/src/assets/logo-estacion-diseno.png').href

const ICONS = {
  phone: '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.910a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
  mail: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
  whatsapp: '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/><path d="M9 9.5c.5 2.5 3 5 5.5 5.5l1-1.2 1.8.8-.3 1.4c-4 .4-8.3-3.9-7.9-7.9l1.4-.3.8 1.8Z"/>',
  upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
  download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
  plus: '<path d="M5 12h14"/><path d="M12 5v14"/>',
  x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
  check: '<path d="M20 6 9 17l-5-5"/>',
  logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
  filter: '<path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>',
  clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
  calendar: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
  graduation: '<path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>',
  reopen: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
  settings: '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3"/><path d="M1 14h6M9 8h6M17 16h6"/>',
  note: '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
  send: '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
  award: '<circle cx="12" cy="8" r="6"/><path d="M15.48 12.89 17 22l-5-3-5 3 1.52-9.11"/>',
  ban: '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
  edit: '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
  list: '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
  eye: '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
}
ICONS.phone = ICONS.phone.replace('9.910a', '9.91a')
const svg = (name, w = 2) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="${w}" stroke-linecap="round" stroke-linejoin="round">${ICONS[name]}</svg>`
const ROUND = {
  wa: ['whatsapp', 'wa'], mail: ['send', 'mail'], ok: ['check', 'ok', 3], eye: ['eye', ''], call: ['phone', 'call'],
  logout: ['logout', ''], x: ['x', ''], edit: ['edit', ''], note: ['note', ''], list: ['list', ''], upload: ['upload', ''],
  settings: ['settings', ''], check: ['check', '', 3], mailbox: ['mail', ''],
}
const COMPANY = { ESTACION: 'Estación Diseño', EFP: 'Grupo EFP' }

function expand(html) {
  return html
    .replace(/\[\[i:(\w+)\]\]/g, (_, k) => { const [ic, cls, w] = ROUND[k]; return `<span class="ib ${cls}">${svg(ic, w)}</span>` })
    .replace(/\[\[(bs?):(\w*)\|([^\]]+)\]\]/g, (_, t, ic, txt) => `<span class="bt${t === 'bs' ? ' solid' : ''}">${ic ? svg(ic) : ''}${txt}</span>`)
    .replace(/\[\[n:(\d+)\]\]/g, '<span class="n">$1</span>')
    .replace(/\[\[k:([^\]]+)\]\]/g, '<kbd>$1</kbd>')
    .replace(/\[\[t:([\w-]+)(?:\|([^\]]+))?\]\]/g, (_, id, label) => `<span class="tab ${id}">${label || id}</span>`)
    .replace(/\[\[s:(\w+)\|([^\]]+)\]\]/g, '<span class="st $1">$2</span>')
    .replace(/\[\[c:(\w+)\]\]/g, (_, c) => `<span class="co ${c}">${COMPANY[c]}</span>`)
}

function build(pages) {
  let src = fs.readFileSync(__dirname + '/manual.src.html', 'utf8')
  src = src.replace(/\{\{img:([\w-]+)\|([^|}]*)(?:\|([^}]*))?\}\}/g, (_, name, cap, cls) => {
    if (!fs.existsSync(`${__dirname}/capturas/${name}.png`)) throw new Error('Falta la captura ' + name)
    return `<figure class="${cls || ''}"><img src="capturas/${name}.png" alt="">${cap ? `<figcaption>${cap}</figcaption>` : ''}</figure>`
  })
  src = src.replace(/\{\{pg:(\d+)\}\}/g, (_, n) => (pages && pages[n]) || '–')
  src = expand(src)
  fs.writeFileSync(__dirname + '/manual.html', src)
  fs.writeFileSync(__dirname + '/estilo.build.css', fs.readFileSync(__dirname + '/estilo.css', 'utf8').replaceAll('FONTS', FONTS))
  fs.writeFileSync(__dirname + '/manual.html', src.replace('href="estilo.css"', 'href="estilo.build.css"'))
  fs.writeFileSync(__dirname + '/cover.build.html', fs.readFileSync(__dirname + '/cover.html', 'utf8').replaceAll('FONTS', FONTS).replace('LOGO', LOGO))
}

;(async () => {
  const pages = process.argv[2] ? JSON.parse(fs.readFileSync(process.argv[2], 'utf8')) : null
  build(pages)
  const browser = await L.launch()
  const page = await browser.newPage()
  for (const [html, pdf] of [['cover.build.html', 'portada.pdf'], ['manual.html', 'cuerpo.pdf']]) {
    await page.goto(pathToFileURL(path.join(__dirname, html)).href, { waitUntil: 'networkidle' })
    await page.evaluate(() => document.fonts.ready)
    await page.pdf({ path: path.join(__dirname, pdf), preferCSSPageSize: true, printBackground: true, outline: true, tagged: true })
    console.log('pdf', pdf)
  }
  await browser.close()
})().catch((e) => { console.error(e); process.exit(1) })
