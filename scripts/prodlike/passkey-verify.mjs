// Prodlike check of ADR 0006 (passkeys + trusted device + remembered sessions) in a real Chromium with a virtual
// platform authenticator (CDP WebAuthn), against https://localhost:8443 — the production image behind Caddy.
//   scripts/prodlike/dc.sh exec -T web php artisan cache:clear && scripts/prodlike/browser.sh node scripts/prodlike/passkey-verify.mjs
// Starts from a platform admin WITH an authenticator app (up.sh) and WITHOUT a passkey: run
//   scripts/prodlike/dc.sh exec -T web php artisan tinker --execute="App\Models\Passkey::query()->delete(); App\Models\TrustedDevice::query()->delete();"
// first when re-running. Every check is REQUIRED (exit 1 on any failure). Screenshots: storage/app/prodlike/screens/pk-*.png
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
            if (m) return m[1] + m[2];
        }
        await new Promise((r) => setTimeout(r, 300));
    }
    throw new Error(`no code mail for ${to}`);
}

const path = (page) => new URL(page.url()).pathname;
const shot = (page, name) => page.screenshot({ path: `${SCREENS}/pk-${name}.png`, fullPage: true }).catch(() => {});
const waitPath = (page, test, timeout = 15_000) => page.waitForURL((u) => test(u.pathname), { timeout }).then(() => true, () => false);

async function emailLogin(page) {
    await fetch(search(ADMIN), { method: 'DELETE' });
    await page.goto(`${BASE}/login`);
    await page.fill('#email', ADMIN);
    await page.locator('form[wire\\:submit="send"] button[type="submit"]').click();
    await page.locator('form[action$="/login/code"] #code').waitFor({ timeout: 15_000 });
    await page.fill('#code', await mailCode(ADMIN));
    await Promise.all([page.waitForNavigation({ timeout: 15_000 }).catch(() => {}), page.locator('form[action$="/login/code"] button[type="submit"]').click()]);
}

/** "Log out" without touching the trusted-device cookie: the session and the remember cookie are gone. */
async function dropSession(context) {
    await context.clearCookies({ name: '__Host-mp_session' });
    await context.clearCookies({ name: '__Host-mp_remember' });
}

const browser = await chromium.launch({ args: ['--ignore-certificate-errors'] });
const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 390, height: 844 }, locale: 'lv-LV' });
const page = await context.newPage();
page.on('dialog', (d) => d.accept());
const consoleErrors = [];
const serverErrors = [];
page.on('console', (m) => m.type() === 'error' && consoleErrors.push(`${path(page)}: ${m.text().slice(0, 160)}`));
page.on('pageerror', (e) => consoleErrors.push(`${path(page)}: ${e.message.slice(0, 160)}`));
page.on('response', (r) => r.status() >= 500 && serverErrors.push(`${r.status()} ${r.request().method()} ${r.url()}`));

const cdp = await context.newCDPSession(page);
await cdp.send('WebAuthn.enable', { enableUI: false });
const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
    options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true },
});
const secret = readFileSync(`${DIR}/admin-totp.txt`, 'utf8').trim();

try {
    // 1. First e-mail login of an admin without a passkey → the one-time offer (TOTP first: the account has one).
    await emailLogin(page);
    check('admin e-mail login → passkey offer', path(page) === '/me/passkey', path(page));
    await shot(page, '01-offer');
    if (await page.locator('form[wire\\:submit="verify"] #code').count()) {
        for (let attempt = 1; attempt <= 2; attempt++) {
            await page.fill('#code', totp(secret));
            await page.locator('form[wire\\:submit="verify"] button[type="submit"]').click();
            if (await page.locator('[data-testid="passkey-register"]').waitFor({ state: 'visible', timeout: 8_000 }).then(() => true, () => false)) break;
            await page.waitForTimeout(30_000 - (Date.now() % 30_000) + 1_000);
        }
    }
    await page.locator('[data-testid="trust-device"]').check();
    await page.locator('[data-testid="passkey-register"]').click();
    check('passkey created, offer left', await waitPath(page, (p) => p !== '/me/passkey'), path(page));

    const trusted = (await context.cookies()).find((c) => c.name === '__Host-mp_trusted');
    check('trusted cookie is __Host- (Secure, HttpOnly, Path=/, host-only, Lax, ~30 days)',
        !!trusted && trusted.secure && trusted.httpOnly && trusted.path === '/' && !trusted.domain.startsWith('.') && trusted.sameSite === 'Lax'
        && trusted.expires * 1000 > Date.now() + 29 * 86_400_000,
        trusted ? JSON.stringify({ domain: trusted.domain, path: trusted.path, secure: trusted.secure, httpOnly: trusted.httpOnly, sameSite: trusted.sameSite }) : 'missing');

    await page.goto(`${BASE}/admin/platform`);
    check('/admin/platform right after creating the passkey (no code)', path(page).startsWith('/admin/platform'), path(page));

    // 2. Signed out: one tap on "Ieiet ar pirkstu / Face ID" is the whole login, MFA included.
    await dropSession(context);
    await page.goto(`${BASE}/login`);
    const button = page.locator('[data-testid="passkey-button"]');
    await button.waitFor({ state: 'visible', timeout: 10_000 });
    await shot(page, '02-login');
    if (path(page) === '/login') await button.click().catch(() => {});
    check('passkey login leaves /login', await waitPath(page, (p) => p !== '/login'), path(page));
    await page.goto(`${BASE}/admin/platform`);
    check('/admin/platform after a passkey login (no TOTP)', path(page).startsWith('/admin/platform'), path(page));
    check('remember cookie set by the passkey login', (await context.cookies()).some((c) => c.name === '__Host-mp_remember'));

    // 3. Signed out again, e-mail login on the trusted device: no offer (has a passkey) and no step-up. The virtual
    //    authenticator answers the e-mail field's autofill request on its own (a phone would only suggest it), so
    //    its user verification is switched off meanwhile — a UV-less answer is refused and the e-mail form is used.
    await dropSession(context);
    await cdp.send('WebAuthn.setUserVerified', { authenticatorId, isUserVerified: false });
    await emailLogin(page);
    await cdp.send('WebAuthn.setUserVerified', { authenticatorId, isUserVerified: true });
    check('e-mail login of an admin with a passkey → no offer', path(page) !== '/me/passkey', path(page));
    await page.goto(`${BASE}/admin/platform`);
    check('/admin/platform on the trusted device without any code', path(page).startsWith('/admin/platform'), path(page));
    await shot(page, '03-platform');

    // 4. Break-glass is a dangerous action: even the trusted device must tap the passkey again.
    await page.goto(`${BASE}/admin/platform/audit`);
    await page.waitForLoadState('networkidle');
    await page.selectOption('#break-glass-building', { index: 1 });
    await page.fill('input[wire\\:model="reason"]', 'Prodlike passkey verification run');
    await page.locator('button[wire\\:click="breakGlass"]').click();
    check('break-glass on a trusted device asks for the step-up', await waitPath(page, (p) => p === '/step-up'), path(page));
    await shot(page, '04-step-up-strong');
    await page.locator('[data-testid="passkey-button"]').click();
    check('one passkey tap returns to the audit page', await waitPath(page, (p) => p.startsWith('/admin/platform/audit')), path(page));

    // 5. "Ieeja un drošība" lists the passkey and this trusted device.
    await page.goto(`${BASE}/me/security`);
    check('/me/security lists 1 passkey and this trusted device',
        (await page.locator('[data-testid="passkey-remove"]').count()) === 1 && (await page.locator('[data-testid="device-revoke"]').count()) >= 1);
    await shot(page, '05-security');

    // 6. Remembered session: the session cookie carries a 7-day expiry when the remember cookie is present.
    const session = (await context.cookies()).find((c) => c.name === '__Host-mp_session');
    check('remembered session cookie lives ~7 days', !!session && session.expires * 1000 > Date.now() + 6 * 86_400_000, session ? new Date(session.expires * 1000).toISOString() : 'missing');

    check('no CSP / JS errors', consoleErrors.length === 0, consoleErrors.slice(0, 5).join(' | '));
    check('no 5xx', serverErrors.length === 0, serverErrors.slice(0, 5).join(' | '));
} catch (e) {
    check('script ran to the end', false, e.message);
    await shot(page, 'zz-error');
} finally {
    await browser.close();
}

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} passed`);
process.exit(failed.length === 0 ? 0 : 1);
