// Walks the main flows of the vendored web interface (public/ui) against a running node, the
// way a brand and a retailer would click through them. Started by scripts/ui-walk.sh, which
// provides a fresh node, a Mailpit inbox and a web server with a nightly export.
// Every main screen is checked with axe (WCAG 2.1 A/AA; serious and critical findings fail),
// and the retailer logs in with the keyboard only.
//   BASE=http://localhost:8091 MAILPIT=http://localhost:8025 SETUP_CODE=... FIXTURES=... node walk.mjs
// INSTALLER=<url> also checks the web installer of a node that is not installed yet.
import { chromium } from 'playwright'
import { AxeBuilder } from '@axe-core/playwright'
import fs from 'node:fs'
import path from 'node:path'

const BASE = process.env.BASE ?? 'http://localhost:8091'
const MAILPIT = process.env.MAILPIT ?? 'http://localhost:8025'
const FIXTURES = process.env.FIXTURES
const SETUP_CODE = process.env.SETUP_CODE
const FEED = process.env.FEED_URL // web address of products.csv as the node sees it
const OUT = process.env.OUT ?? fs.mkdtempSync('/tmp/obx-ui-walk-')
const admin = { email: 'admin@kestrel.example', password: 'kestrel-admin-2026' }
const buyer = { email: 'buyer@shop.example', password: 'kestrel-shop-2026' }

let failures = 0
const problems = []
const browser = await chromium.launch()

async function context(name) {
  const ctx = await browser.newContext({ locale: 'en-GB', acceptDownloads: true })
  await ctx.addInitScript(() => localStorage.setItem('obx.lang', 'en'))
  const page = await ctx.newPage()
  page.setDefaultTimeout(10000)
  page.on('pageerror', (e) => problems.push(`[${name}] page error: ${e.message}`))
  page.on('response', (r) => {
    // HEAD 404 on blobs is the normal "not uploaded yet" answer.
    if (r.status() >= 400 && !(r.request().method() === 'HEAD' && r.status() === 404)) {
      problems.push(`[${name}] HTTP ${r.status()} ${r.request().method()} ${new URL(r.url()).pathname}`)
    }
  })
  return { ctx, page }
}

async function step(page, name, fn) {
  try {
    await fn()
    console.log(`ok    ${name}`)
  } catch (e) {
    failures++
    console.log(`FAIL  ${name}: ${e.message.split('\n')[0]}`)
    await page.screenshot({ path: path.join(OUT, name.replace(/\W+/g, '-') + '.png'), fullPage: true }).catch(() => {})
  }
}

const a11yFindings = []
// axe on the current screen; serious and critical WCAG 2.1 A/AA findings fail the walk.
async function a11y(page, screen) {
  await page.waitForLoadState('networkidle').catch(() => {})
  const r = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze()
  const bad = r.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')
  for (const v of bad) a11yFindings.push(`${screen}: ${v.id} (${v.impact}) ${v.nodes.length}× — ${v.nodes[0]?.target?.join(' ')}`)
  console.log(`${bad.length ? 'FAIL' : 'ok'}    accessibility: ${screen}${bad.length ? ' — ' + bad.map((v) => v.id).join(', ') : ''}`)
  if (bad.length) failures++
}

async function mails() {
  const list = await (await fetch(`${MAILPIT}/api/v1/messages`)).json()
  return Promise.all(list.messages.map(async (m) => ({ to: m.To[0].Address, subject: m.Subject, text: (await (await fetch(`${MAILPIT}/api/v1/message/${m.ID}`)).json()).Text })))
}

async function download(page, click) {
  const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 20000 }), click()])
  const file = await dl.path()
  return { name: dl.suggestedFilename(), bytes: fs.statSync(file).size }
}

const A = await context('admin')
let p = A.page
if (process.env.INSTALLER) {
  await step(p, 'web installer', async () => {
    await p.goto(process.env.INSTALLER + '/install')
    await p.getByRole('heading', { name: 'Install your brand portal' }).waitFor()
    await a11y(p, 'installer')
    await p.goto(process.env.INSTALLER + '/install?lang=de')
    await p.getByRole('heading', { name: 'Installieren Sie Ihr Händlerportal' }).waitFor()
  })
}
await step(p, 'setup wizard', async () => {
  await p.goto(BASE + '/')
  await p.waitForURL(/\/setup$/)
  await a11y(p, 'setup wizard')
  await p.getByLabel('Setup code').fill(SETUP_CODE)
  await p.getByRole('button', { name: 'Next' }).click()
  await p.getByLabel('Company name').fill('Kestrel Outfitters')
  await p.getByLabel('First brand').fill('Kestrel')
  await p.getByRole('button', { name: 'Next' }).click()
  await p.getByRole('button', { name: 'Next' }).click()
  await p.getByLabel('Your name').fill('Iris Admin')
  await p.getByLabel('Email address').fill(admin.email)
  await p.getByLabel('Password', { exact: true }).fill(admin.password)
  await p.getByLabel('Repeat the password').fill(admin.password)
  await p.getByRole('button', { name: 'Finish and log in' }).click()
  await p.getByRole('heading', { name: 'Hello Iris' }).waitFor()
  await a11y(p, 'admin home')
})
await step(p, 'import CSV and images zip', async () => {
  await p.goto(BASE + '/admin/imports')
  await a11y(p, 'imports')
  await p.getByTestId('import-file-input').setInputFiles([path.join(FIXTURES, 'products.csv'), path.join(FIXTURES, 'assets.csv'), process.env.IMAGES_ZIP])
  await p.getByRole('button', { name: /^Start/ }).click()
  await p.getByText('Everything was imported without problems.').waitFor({ timeout: 60000 })
  const text = await p.locator('main').innerText()
  if (!/New\s+76/.test(text)) throw new Error('expected 76 new objects, got: ' + text.replace(/\s+/g, ' ').slice(0, 200))
})

const R = await context('retailer')
await step(R.page, 'retailer requests access', async () => {
  const q = R.page
  await q.goto(BASE + '/request-access')
  await a11y(q, 'request access')
  await q.getByLabel('Shop or company name').fill('Harbour Street Shoes')
  await q.getByLabel('Website').fill('https://harbour.example')
  await q.getByLabel('Your name').fill('Sam Buyer')
  await q.getByLabel('Email address').fill(buyer.email)
  await q.getByRole('button', { name: 'Send request' }).click()
  await q.getByText('Request sent').waitFor()
})
await step(p, 'admin approves with an invitation', async () => {
  await p.goto(BASE + '/admin/requests')
  await p.getByRole('button', { name: 'Approve' }).first().waitFor()
  await a11y(p, 'access requests')
  await p.getByRole('button', { name: 'Approve' }).first().click()
  await p.getByRole('dialog').getByRole('button', { name: 'Approve' }).click()
  await p.getByText('Invitation sent to ' + buyer.email).waitFor()
})
let inviteUrl
await step(p, 'e-mails: one invitation after approval', async () => {
  await new Promise((r) => setTimeout(r, 1500))
  const toBuyer = (await mails()).filter((m) => m.to === buyer.email)
  const subjects = toBuyer.map((m) => m.subject).sort()
  if (subjects.length !== 2 || !subjects.some((s) => s.startsWith('We received your request')) || !subjects.some((s) => s.includes('invites you'))) throw new Error('mails to the buyer: ' + subjects.join(' / '))
  inviteUrl = toBuyer.map((m) => m.text.match(/https?:\/\/\S+\/invite\/\S+/)?.[0]).find(Boolean)
  if (!inviteUrl) throw new Error('no invitation link')
})
p = R.page
await step(p, 'retailer accepts the invitation', async () => {
  await p.goto(inviteUrl)
  await a11y(p, 'accept invitation')
  await p.getByLabel('New password').fill(buyer.password)
  await p.getByLabel('Repeat the password').fill(buyer.password)
  await p.getByRole('button', { name: 'Create my account' }).click()
  await p.getByText('8 articles').waitFor()
  await a11y(p, 'portal catalog')
})
await step(p, 'browse, search and open an article', async () => {
  await p.getByPlaceholder(/Search by article number/).fill('KT-2001')
  await p.getByText('1 article').waitFor()
  await p.getByPlaceholder(/Search by article number/).fill('')
  await p.getByText('8 articles').waitFor()
  await p.locator('main a[href*="products/"]').first().click()
  await p.getByRole('heading', { name: /Sizes and EAN/ }).waitFor()
  await a11y(p, 'article page')
  const f = await download(p, () => p.getByRole('button', { name: 'Download this image' }).click())
  if (!f.bytes) throw new Error('empty image download')
})
await step(p, 'EAN list: matches and unmatched', async () => {
  await p.goto(BASE + '/ean')
  await a11y(p, 'EAN list')
  await p.getByLabel('EANs').fill('2000100101018\n2000999999998\n12345')
  await p.getByRole('button', { name: 'Find articles' }).click()
  await p.getByText('1 found').waitFor()
  await p.getByText('1 not found').waitFor()
  await p.getByText('1 not a valid code').waitFor()
  await p.getByRole('button', { name: /Add 1 article to selection/ }).click()
  await p.getByText('1 article added.').waitFor()
})
await step(p, 'selection zip and CSV', async () => {
  await p.goto(BASE + '/selection')
  await a11y(p, 'selection')
  const zip = await download(p, () => p.getByRole('button', { name: /Download \d+ images? \(zip\)/ }).or(p.getByRole('link', { name: /Download \d+ images? \(zip\)/ })).first().click())
  if (!zip.name.endsWith('.zip') || zip.bytes < 1000) throw new Error(`zip ${zip.name} ${zip.bytes}`)
  const csv = await download(p, () => p.getByRole('button', { name: 'Article data (CSV)' }).or(p.getByRole('link', { name: 'Article data (CSV)' })).first().click())
  if (!csv.name.endsWith('.csv')) throw new Error('csv ' + csv.name)
})
await step(p, 'whole catalog as a background zip', async () => {
  await p.goto(BASE + '/')
  await p.getByRole('button', { name: 'Download all' }).click()
  await p.getByRole('dialog').getByRole('button').filter({ hasText: /prepare|start/i }).last().click()
  await p.goto(BASE + '/downloads')
  await p.getByText('Ready', { exact: true }).first().waitFor({ timeout: 60000 })
  await a11y(p, 'downloads')
  const zip = await download(p, () => p.locator('main').getByRole('link', { name: 'Download', exact: true }).or(p.locator('main').getByRole('button', { name: 'Download', exact: true })).first().click())
  if (zip.bytes < 1000) throw new Error('zip too small')
})

p = A.page
await step(p, 'API key for the retailer\'s software', async () => {
  await p.goto(BASE + '/admin/retailers')
  await a11y(p, 'retailers')
  await p.getByRole('link', { name: /Harbour Street Shoes/ }).first().click()
  await p.getByLabel('Name of the key').waitFor()
  await a11y(p, 'retailer')
  await p.getByLabel('Name of the key').fill('Shop system')
  await p.getByRole('button', { name: 'Create API key' }).click()
  await p.getByText('Client secret').first().waitFor()
  await p.getByRole('button', { name: 'I have saved it' }).click()
  await p.getByText(/obxc_/).first().waitFor()
})
await step(p, 'access key for automatic upload', async () => {
  await p.goto(BASE + '/admin/automation')
  await a11y(p, 'automation')
  await p.getByRole('tab', { name: 'Access keys' }).or(p.getByRole('button', { name: 'Access keys' })).first().click()
  await p.getByLabel('Name', { exact: true }).fill('PIM nightly export')
  await p.getByRole('button', { name: 'Create access key' }).click()
  await p.getByText(/Access key for/).first().waitFor()
})
if (FEED) {
  await step(p, 'scheduled import from a web address', async () => {
    await p.goto(BASE + '/admin/automation/sources/new')
    await p.getByLabel('Name', { exact: true }).fill('Nightly PIM export')
    await p.getByLabel('Web address of the CSV or zip').fill(FEED)
    await p.locator('form button[type=submit]').last().click()
    await p.getByRole('button', { name: 'Test connection' }).first().click()
    await p.getByText(/Connected\. \d+ files? found/).waitFor({ timeout: 20000 })
    await p.getByRole('button', { name: 'Run now' }).first().click()
    await p.getByRole('link', { name: 'Follow it' }).click()
    await p.getByText('Everything was imported without problems.').waitFor({ timeout: 60000 })
  })
}
await step(p, 'statistics show the retailer\'s downloads', async () => {
  await p.goto(BASE + '/admin/stats')
  await p.getByText('Harbour Street Shoes downloaded an image').first().waitFor()
  await a11y(p, 'statistics')
})
for (const [screen, url] of [['catalog (admin)', '/admin/catalog'], ['settings', '/admin/settings'], ['my account', '/account']]) {
  await step(p, 'open ' + screen, async () => {
    await p.goto(BASE + url)
    await p.locator('main h1, main h2').first().waitFor()
    await a11y(p, screen)
  })
}

// Keyboard only: log in as the retailer without a mouse.
const K = await context('keyboard')
await step(K.page, 'log in with the keyboard only', async () => {
  const k = K.page
  await k.goto(BASE + '/login')
  await k.getByLabel('Email address').waitFor()
  await a11y(k, 'login')
  let reached = false
  for (let i = 0; i < 15 && !reached; i++) {
    await k.keyboard.press('Tab')
    reached = await k.evaluate(() => document.activeElement?.getAttribute('type') === 'email' || document.activeElement?.getAttribute('autocomplete') === 'email' || document.activeElement?.getAttribute('autocomplete') === 'username')
  }
  if (!reached) throw new Error('the e-mail field cannot be reached with Tab')
  await k.keyboard.type(buyer.email)
  await k.keyboard.press('Tab')
  const onPassword = await k.evaluate(() => document.activeElement?.getAttribute('type') === 'password')
  if (!onPassword) throw new Error('Tab from the e-mail field does not reach the password field')
  await k.keyboard.type(buyer.password)
  await k.keyboard.press('Enter')
  await k.getByText('8 articles').waitFor()
  const visibleFocus = await k.evaluate(() => {
    const a = document.querySelector('main a[href*="products/"]')
    if (!a) return 'no article link'
    a.focus()
    const s = getComputedStyle(a)
    return s.outlineStyle !== 'none' || s.boxShadow !== 'none' ? 'ok' : 'no visible focus on article links'
  })
  if (visibleFocus !== 'ok') throw new Error(visibleFocus)
})

await browser.close()
for (const x of problems) console.log('note  ' + x)
for (const x of a11yFindings) console.log('a11y  ' + x)
console.log(failures ? `${failures} step(s) failed; screenshots in ${OUT}` : 'All flows passed.')
process.exit(failures ? 1 : 0)
