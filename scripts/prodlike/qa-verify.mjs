// Prodlike re-check of the production QA findings of 2026-10-20 (BRAIN/TASK.md FIX-001…FIX-007) in a real Chromium
// against https://localhost:8443 (production image, Caddy in front like Traefik). Run inside the stack:
//   scripts/prodlike/dc.sh exec -T web php artisan cache:clear && scripts/prodlike/browser.sh node scripts/prodlike/qa-verify.mjs
// Every check is REQUIRED (exit 1 on any failure). Screenshots: storage/app/prodlike/screens/qa-*.png
import { createHmac } from 'node:crypto';
import { mkdirSync, readFileSync } from 'node:fs';
import { chromium } from 'playwright';

const BASE = process.env.BASE_URL ?? 'https://localhost:8443';
const MAILPIT = process.env.MAILPIT_URL ?? 'http://mailpit:8025';
const DIR = process.env.PRODLIKE_DIR ?? 'storage/app/prodlike';
const ADMIN = process.env.PRODLIKE_ADMIN_EMAIL ?? 'admin@example.test';
const SLUG = process.env.PRODLIKE_BUILDING ?? 'puces-51';
const SCREENS = `${DIR}/screens`;
mkdirSync(SCREENS, { recursive: true });

const results = [];
const check = (name, ok, detail = '') => {
    results.push({ name, ok });
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`);
};

function totp(secret, now = Date.now()) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of secret.replace(/[\s=]/g, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
    const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(now / 1000 / 30)));
    const h = createHmac('sha1', key).update(counter).digest();
    const o = h[h.length - 1] & 0xf;
    return String((h.readUInt32BE(o) & 0x7fffffff) % 1_000_000).padStart(6, '0');
}

const search = (to) => `${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:"${to}"`)}`;
async function mailCode(to, seconds = 20) {
    const deadline = Date.now() + seconds * 1000;
    while (Date.now() < deadline) {
        const id = (await (await fetch(`${search(to)}&limit=1`)).json()).messages?.[0]?.ID;
        if (id) {
            const text = (await (await fetch(`${MAILPIT}/api/v1/message/${id}`)).json()).Text ?? '';
            const m = text.match(/(?<!\d)(\d{3}) (\d{3})(?!\d)/);
            if (m) return { code: m[1] + m[2], text };
        }
        await new Promise((r) => setTimeout(r, 300));
    }
    throw new Error(`no code mail for ${to}`);
}

const lang = (page) => page.locator('html').getAttribute('lang');
const path = (page) => new URL(page.url()).pathname;
const shot = (page, name) => page.screenshot({ path: `${SCREENS}/qa-${name}.png`, fullPage: true }).catch(() => {});
const nav = (page, fn) => Promise.all([page.waitForNavigation({ timeout: 15_000 }).catch(() => {}), fn()]);

const browser = await chromium.launch({ args: ['--ignore-certificate-errors'] });
const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 390, height: 844 }, locale: 'lv-LV' });
const page = await context.newPage();
const consoleErrors = [];
const serverErrors = [];
page.on('console', (m) => m.type() === 'error' && consoleErrors.push(`${path(page)}: ${m.text().slice(0, 160)}`));
page.on('pageerror', (e) => consoleErrors.push(`${path(page)}: ${e.message.slice(0, 160)}`));
page.on('response', (r) => r.status() >= 500 && serverErrors.push(`${r.status()} ${r.request().method()} ${r.url()}`));

try {
    // FIX-002: the visitor's IP, not the proxy's (Caddy appends 127.0.0.1 like Traefik appends the TCP peer).
    for (const ip of ['203.0.113.7', '198.51.100.20']) {
        const r = await context.request.get(`${BASE}/up/ip`, { headers: { 'X-Forwarded-For': ip } });
        const prefix = (await r.json()).ip_prefix;
        check(`/up/ip resolves visitor ${ip}`, prefix === ip.split('.').slice(0, 3).join('.') + '.0/24', `ip_prefix=${prefix}`);
    }

    // FIX-003: the toggle after a service-worker fetch returns to the same page, in Russian.
    await page.goto(`${BASE}/login/pin`);
    await page.evaluate(() => fetch('/sw.js', { credentials: 'same-origin' }).then((r) => r.text()));
    await nav(page, () => page.locator('form[action$="/locale/ru"] button').first().click());
    check('RU toggle after /sw.js stays on /login/pin', path(page) === '/login/pin' && (await lang(page)) === 'ru', `${path(page)} lang=${await lang(page)}`);
    await shot(page, '01-toggle-ru');

    // FIX-001 + FIX-003: e-mail code login in RU → stays RU; remember cookie set.
    await page.goto(`${BASE}/login`);
    await fetch(search(ADMIN), { method: 'DELETE' });
    await page.fill('#email', ADMIN);
    await page.locator('form[wire\\:submit="send"] button[type="submit"]').click();
    await page.locator('form[action$="/login/code"] #code').waitFor({ timeout: 15_000 });
    const mail = await mailCode(ADMIN);
    check('login mail in RU without English layout strings', /Здравствуйте|Вход/.test(mail.text) && !/Hello!|All rights reserved|having trouble/.test(mail.text));
    await page.fill('#code', mail.code);
    await nav(page, () => page.locator('form[action$="/login/code"] button[type="submit"]').click());
    const cookies = await context.cookies();
    check('remember cookie set at login', cookies.some((c) => c.name === '__Host-mp_remember'));

    // FIX-001: the session expires (cookie gone) — the remember cookie logs the person back in.
    await context.clearCookies({ name: '__Host-mp_session' });
    const back = await page.goto(`${BASE}/me/security`);
    check('remember cookie alone re-authenticates', path(page) === '/me/security' && back.status() === 200, `landed ${path(page)}`);
    check('language kept after login', (await lang(page)) === 'ru', `lang=${await lang(page)}`);

    // Platform: TOTP step-up, then the pages that failed.
    await page.goto(`${BASE}/admin/platform`);
    if (path(page).startsWith('/step-up')) {
        const secret = readFileSync(`${DIR}/admin-totp.txt`, 'utf8').trim();
        for (let attempt = 1; attempt <= 2; attempt++) {
            await page.fill('#code', totp(secret));
            await page.locator('form[wire\\:submit="verify"] button[type="submit"]').click();
            if (await page.waitForURL((u) => u.pathname.startsWith('/admin/platform'), { timeout: 10_000 }).then(() => true, () => false)) break;
            await page.waitForTimeout(30_000 - (Date.now() % 30_000) + 1_000);
        }
    }
    check('TOTP step-up → /admin/platform', path(page).startsWith('/admin/platform'), path(page));

    const list = await page.goto(`${BASE}/admin/platform/buildings`);
    await page.waitForLoadState('networkidle');
    check('/admin/platform/buildings has a Create button', list.status() === 200 && (await page.locator('a[href$="/admin/platform/buildings/create"]').count()) > 0);
    check('panel LV/RU switch present', (await page.locator('[data-testid="panel-language-switch"]').count()) > 0);
    check('no ui-avatars.com image', (await page.locator('img[src*="ui-avatars.com"]').count()) === 0);
    await shot(page, '02-buildings');

    const edit = await page.goto(`${BASE}/admin/platform/buildings/${SLUG}/edit`);
    await page.waitForLoadState('networkidle');
    check(`/admin/platform/buildings/${SLUG}/edit opens`, edit.status() === 200, `HTTP ${edit.status()}`);
    await shot(page, '03-building-edit');

    // Generator: loads the stored stairwells; an emptied field and a conflicting input never 500.
    await page.goto(`${BASE}/admin/platform/generator`);
    await page.waitForLoadState('networkidle');
    const picker = page.locator('select[wire\\:model\\.live="building"]');
    const value = await picker.locator('option', { hasText: process.env.PRODLIKE_BUILDING_NAME ?? 'Pūces' }).first().getAttribute('value');
    await picker.selectOption(value ?? '');
    await page.locator('[data-testid="generator-existing"]').waitFor({ timeout: 10_000 }).catch(() => {});
    check('generator shows the existing structure', (await page.locator('[data-testid="generator-existing"] li').count()) > 0);
    const sections = page.locator('input[wire\\:model\\.live\\.debounce\\.500ms="sections"]');
    await sections.fill('');
    await page.waitForTimeout(1200);
    await sections.fill('5');
    await page.locator('input[wire\\:model="floors"]').fill('7');
    await page.locator('button[wire\\:click="previewGrid"]').click();
    await page.locator('[data-testid="generator-conflicts"]').waitFor({ timeout: 10_000 }).catch(() => {});
    check('conflicting re-run is refused with a message', (await page.locator('[data-testid="generator-conflicts"] li').count()) > 0);
    await shot(page, '04-generator-conflict');

    const audit = await page.goto(`${BASE}/admin/platform/audit`);
    await page.waitForLoadState('networkidle');
    check('audit verify button rendered', audit.status() === 200 && (await page.locator('[wire\\:click="verifyPlatform"]').count()) > 0);

    // 404 in the visitor's language (RU now saved on the account and in the cookie).
    const missing = await page.goto(`${BASE}/nonexistent-page`);
    check('404 in RU', missing.status() === 404 && (await lang(page)) === 'ru', `HTTP ${missing.status()} lang=${await lang(page)}`);
    await nav(page, () => page.goto(`${BASE}/login/pin`).catch(() => {}));

    const csp = consoleErrors.filter((e) => /Content Security Policy/.test(e));
    check('no CSP violations', csp.length === 0, csp.slice(0, 3).join(' | '));
    check('no 5xx responses', serverErrors.length === 0, serverErrors.slice(0, 3).join(' | '));
} catch (e) {
    check('verification run', false, e.message.split('\n')[0]);
    await shot(page, '99-error');
} finally {
    await browser.close();
}

const failed = results.filter((r) => !r.ok).length;
console.log(`\n${failed ? 'FAILED' : 'OK'}: ${failed} failure(s) of ${results.length}.`);
process.exit(failed ? 1 : 0);
