// Playwright: el del proyecto si está instalado (npm i -D playwright) o el de playwright-cli global.
const path = require('path')
try {
  module.exports = require('playwright')
} catch {
  module.exports = require(path.join(process.env.APPDATA || '', 'npm/node_modules/@playwright/cli/node_modules/playwright'))
}
