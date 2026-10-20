// Prodlike smoke test (BRAIN/docs/PRODLIKE.md): a real Chromium against https://localhost:8443 — the same origin,
// TLS hop, cookies and production image as Coolify. Run with scripts/prodlike/browser.sh (inside the stack).
//
// REQUIRED (exit 1 on failure): /up 200 · /login renders in LV · /login renders in RU after the RU toggle ·
//   e-mail code login end to end (code read from Mailpit) for the platform admin.
// EXTENDED (reported as WARN, exit 0): the toggle returns to the same page · TOTP step-up into /platform ·
//   RU after login · no JS errors / 5xx.
// Screenshots: storage/app/prodlike/screens/*.png
import { createHmac } from 'node:crypto';
import { mkdirSync, readFileSync } from 'node:fs';
import { chromium } from 'playwright';

const BASE = process.env.BASE_URL ?? 'https://localhost:8443';
const MAILPIT = process.env.MAILPIT_URL ?? 'http://mailpit:8025';
const DIR = process.env.PRODLIKE_DIR ?? 'storage/app/prodlike';
const ADMIN = process.env.PRODLIKE_ADMIN_EMAIL ?? 'admin@example.test';
const SCREENS = `${DIR}/screens`;
mkdirSync(SCREENS, { recursive: true });

const results = [];
const record = (level, name, ok, detail = '') => {
    results.push({ level, name, ok, detail });
    console.log(`${ok ? 'PASS' : level === 'required' ? 'FAIL' : 'WARN'}  ${name}${detail ? ` — ${detail}` : ''}`);
};

/** RFC 6238 TOTP (SHA-1, 6 digits, 30 s) from a base32 secret — what an authenticator app shows. */
export function totp(secret, now = Date.now()) {
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

/** Deletes old mail to $to, so the next code read is the new one. */
async function clearMail(to) {
    await fetch(search(to), { method: 'DELETE' });
}

/** The 6-digit code ("123 456") from the newest Mailpit message to $to. */
async function mailCode(to, seconds = 20) {
    const deadline = Date.now() + seconds * 1000;
    while (Date.now() < deadline) {
        const id = (await (await fetch(`${search(to)}&limit=1`)).json()).messages?.[0]?.ID;
        if (id) {
            const text = (await (await fetch(`${MAILPIT}/api/v1/message/${id}`)).json()).Text ?? '';
            const m = text.match(/(?<!\d)(\d{3}) (\d{3})(?!\d)/);
            if (m) return m[1] + m[2];
        }
        await new Promise((r) => setTimeout(r, 300));
    }
    throw new Error(`no code mail for ${to} within ${seconds}s`);
}

const lang = (page) => page.locator('html').getAttribute('lang');
const shot = (page, name) => page.screenshot({ path: `${SCREENS}/${name}.png`, fullPage: true });

// --ignore-certificate-errors as well: Chromium refuses to register the service worker (/sw.js) over an untrusted
// certificate even with ignoreHTTPSErrors, and production DOES register it.
const browser = await chromium.launch({ args: ['--ignore-certificate-errors'] });
const context = await browser.newContext({
    ignoreHTTPSErrors: true, // Caddy `tls internal` certificate
    viewport: { width: 390, height: 844 },
    locale: 'lv-LV',
});
const page = await context.newPage();
const jsErrors = [];
const serverErrors = [];
page.on('pageerror', (e) => jsErrors.push(`${page.url()}: ${e.message}`));
page.on('console', (m) => m.type() === 'error' && jsErrors.push(`${page.url()}: ${m.text()}`));
page.on('response', (r) => r.status() >= 500 && serverErrors.push(`${r.status()} ${r.request().method()} ${r.url()}`));

try {
    // 1. Health.
    const up = await context.request.get(`${BASE}/up`);
    record('required', 'GET /up', up.status() === 200, `HTTP ${up.status()}`);

    // 2. Login page, LV.
    const res = await page.goto(`${BASE}/login`);
    record('required', '/login in LV', res.status() === 200 && (await lang(page)) === 'lv', `HTTP ${res.status()}, lang=${await lang(page)}`);
    await shot(page, '01-login-lv');

    // 3. RU toggle (plain POST form, redirects to url()->previous()) → /login in RU, then back to LV.
    await Promise.all([page.waitForNavigation({ timeout: 15_000 }).catch(() => {}),
        page.locator('form[action$="/locale/ru"] button').first().click()]);
    const landed = new URL(page.url()).pathname;
    record('extended', 'RU toggle returns to the same page', landed === '/login', `landed on ${landed}`);
    await shot(page, '02-after-ru-toggle');
    await page.goto(`${BASE}/login`);
    record('required', '/login in RU (after the toggle)', (await lang(page)) === 'ru', `lang=${await lang(page)}`);
    await shot(page, '02-login-ru');
    await Promise.all([page.waitForNavigation({ timeout: 15_000 }).catch(() => {}),
        page.locator('form[action$="/locale/lv"] button').first().click()]);

    // 4. E-mail code login (Livewire send → native POST /login/code).
    await page.goto(`${BASE}/login`);
    await clearMail(ADMIN);
    await page.fill('#email', ADMIN);
    await page.locator('form[wire\\:submit="send"] button[type="submit"]').click();
    await page.locator('form[action$="/login/code"] #code').waitFor({ timeout: 15_000 });
    const code = await mailCode(ADMIN);
    await page.fill('#code', code);
    await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15_000 }).catch(() => {}),
        page.locator('form[action$="/login/code"] button[type="submit"]').click()]);
    const afterLogin = new URL(page.url()).pathname;
    const cookies = await context.cookies();
    const session = cookies.find((c) => c.name === '__Host-mp_session');
    record('required', 'e-mail code login', !afterLogin.startsWith('/login') && !!session,
        `landed on ${afterLogin}, session cookie ${session ? 'set' : 'MISSING'}`);
    await shot(page, '03-after-login');

    // 5. Platform panel: TOTP step-up with the secret saved by up.sh.
    try {
        if (!afterLogin.startsWith('/platform')) await page.goto(`${BASE}/platform`);
        if (new URL(page.url()).pathname.startsWith('/step-up')) {
            const secret = readFileSync(`${DIR}/admin-totp.txt`, 'utf8').trim();
            // A time step is accepted once (replay markers): a run right after another may hit a used step, so on a
            // rejection wait for the next 30 s window and try once more.
            for (let attempt = 1; attempt <= 2; attempt++) {
                await page.fill('#code', totp(secret));
                await page.locator('form[wire\\:submit="verify"] button[type="submit"]').click();
                const ok = await page.waitForURL((u) => u.pathname.startsWith('/platform'), { timeout: 10_000 }).then(() => true, () => false);
                if (ok) break;
                if (attempt === 2) throw new Error('TOTP code rejected twice');
                await page.waitForTimeout(30_000 - (Date.now() % 30_000) + 1_000);
            }
        }
        await page.waitForLoadState('networkidle');
        const path = new URL(page.url()).pathname;
        record('extended', 'TOTP step-up → /platform', path.startsWith('/platform'), `at ${path}`);
        await shot(page, '04-platform');
    } catch (e) {
        record('extended', 'TOTP step-up → /platform', false, `${e.message.split('\n')[0]} (at ${new URL(page.url()).pathname})`);
        await shot(page, '04-platform');
    }

    // 6. RU after login: the account page carries the same LV|RU toggle.
    try {
        await page.goto(`${BASE}/me/security`);
        const toggle = page.locator('form[action$="/locale/ru"] button').first();
        if ((await toggle.count()) === 0) {
            record('extended', 'RU after login', false, 'no RU toggle on /me/security');
        } else {
            await Promise.all([page.waitForNavigation({ timeout: 15_000 }).catch(() => {}), toggle.click()]);
            await page.goto(`${BASE}/platform`);
            await page.waitForLoadState('networkidle');
            record('extended', 'RU after login', (await lang(page)) === 'ru', `/platform lang=${await lang(page)}`);
            await shot(page, '05-platform-ru');
        }
    } catch (e) {
        record('extended', 'RU after login', false, e.message.split('\n')[0]);
    }

    record('extended', 'no JavaScript errors', jsErrors.length === 0, jsErrors.slice(0, 5).join(' | '));
    record('extended', 'no 5xx responses', serverErrors.length === 0, serverErrors.slice(0, 5).join(' | '));
} catch (e) {
    record('required', 'smoke run', false, e.message.split('\n')[0]);
    await shot(page, '99-error').catch(() => {});
} finally {
    await browser.close();
}

const failed = results.filter((r) => r.level === 'required' && !r.ok).length;
const warned = results.filter((r) => r.level === 'extended' && !r.ok).length;
console.log(`\n${failed ? 'FAILED' : 'OK'}: ${failed} required failure(s), ${warned} warning(s). Screenshots: ${SCREENS}`);
process.exit(failed ? 1 : 0);
