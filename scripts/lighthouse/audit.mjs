#!/usr/bin/env node
/*
 * Mājas Palīgs — Lighthouse mobile accessibility audit (MVP-SPEC A.11 AC 16: Accessibility ≥ 95).
 *
 * Runs INSIDE the `browser` compose service (Node 24 + Playwright Chromium) against the app that the same container
 * serves on http://localhost:8080 with the LOCAL demo data (php artisan db:seed --class=DemoSeeder → Pūces iela 51,
 * fictional accounts @example.test). Use the wrapper: scripts/dev.sh lighthouse
 *
 *  1. guest screens (landing, /join, /login, /login/pin, /privacy) are audited first;
 *  2. the demo elder logs in with the e-mail code read from Mailpit (no backdoor, the real login flow);
 *  3. the resident screens are audited in the same browser profile (cookies kept: disableStorageReset).
 *
 * Lighthouse uses its default MOBILE profile (Moto G Power viewport, 4× CPU slowdown, slow 4G).
 * Output: storage/app/lighthouse/<page>.html + summary.json; exit code 1 when any page scores below LH_MIN.
 *
 * Env: BASE_URL (http://localhost:8080), MAILPIT_URL (http://mailpit:8025), LH_EMAIL (demo-elder5@example.test),
 *      LH_BUILDING (puces-51), LH_MIN (95), LH_OUT (storage/app/lighthouse).
 */
import { mkdir, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { chromium } from 'playwright';
import lighthouse from 'lighthouse';

const BASE = (process.env.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const MAILPIT = (process.env.MAILPIT_URL || 'http://mailpit:8025').replace(/\/$/, '');
const EMAIL = process.env.LH_EMAIL || 'demo-elder5@example.test';
const SLUG = process.env.LH_BUILDING || 'puces-51';
const MIN = Number(process.env.LH_MIN || 95);
const OUT = process.env.LH_OUT || 'storage/app/lighthouse';
const PORT = 9222;

const guestPages = [
    ['landing', '/'],
    ['join', '/join'],
    ['login', '/login'],
    ['login-pin', '/login/pin'],
    ['privacy', '/privacy'],
];
const residentPages = [
    ['home', `/b/${SLUG}`],
    ['apartment', `/b/${SLUG}/me`],
    ['heating', `/b/${SLUG}/heating`],
    ['heating-report-sheet', `/b/${SLUG}/heating?report=1`],
    ['water', `/b/${SLUG}/water`],
    ['parking-lookup', `/b/${SLUG}/parking`],
    ['parking-mine', `/b/${SLUG}/parking/mine`],
    ['parking-sighting', `/b/${SLUG}/parking/sighting`],
    ['parking-progress', `/b/${SLUG}/parking/progress`],
    ['me-privacy', '/me/privacy'],
    ['me-security', '/me/security'],
];

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** Newest 6-digit code ("123 456") mailed to `to` after `since` (ms epoch), polled from the Mailpit API. */
async function mailpitCode(to, since) {
    for (let i = 0; i < 60; i++) {
        const search = await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:"${to}"`)}&limit=1`).then((r) => r.json());
        const msg = search.messages && search.messages[0];
        if (msg && Date.parse(msg.Created) >= since - 2000) {
            const full = await fetch(`${MAILPIT}/api/v1/message/${msg.ID}`).then((r) => r.json());
            const m = /(?<!\d)(\d{3}) (\d{3})(?!\d)/.exec(full.Text || '');
            if (m) {
                return m[1] + m[2];
            }
        }
        await sleep(500);
    }
    throw new Error(`No login code for ${to} in Mailpit (${MAILPIT}).`);
}

async function audit(name, path, results) {
    const runner = await lighthouse(BASE + path, {
        port: PORT,
        output: 'html',
        onlyCategories: ['accessibility'],
        disableStorageReset: true, // keep the login session between pages
        logLevel: 'error',
    });
    const lhr = runner.lhr;
    const score = Math.round((lhr.categories.accessibility.score ?? 0) * 100);
    const failing = Object.values(lhr.audits)
        .filter((a) => a.score !== null && a.score < 1 && a.scoreDisplayMode === 'binary')
        .map((a) => a.id);
    await writeFile(join(OUT, `${name}.html`), runner.report);
    results.push({ page: name, path, finalUrl: lhr.finalDisplayedUrl, score, failing });
    console.log(`${score >= MIN ? 'ok  ' : 'FAIL'} ${String(score).padStart(3)}  ${name.padEnd(22)} ${path}${failing.length ? `  [${failing.join(', ')}]` : ''}`);
}

await mkdir(OUT, { recursive: true });
const profile = join(tmpdir(), `mp-lighthouse-${Date.now()}`);
// A persistent context IS Chromium's default context, so Lighthouse's new tabs share its cookies.
const context = await chromium.launchPersistentContext(profile, {
    headless: true,
    args: [`--remote-debugging-port=${PORT}`],
});
const results = [];

try {
    for (const [name, path] of (process.env.LH_SKIP_GUEST ? [] : guestPages)) {
        await audit(name, path, results);
    }

    const page = context.pages()[0] || (await context.newPage());
    await page.goto(`${BASE}/login`);
    const since = Date.now();
    await page.fill('#email', EMAIL);
    await page.locator('form[wire\\:submit] button[type=submit]').click();
    await page.waitForSelector('input[name=code]');
    await page.fill('input[name=code]', await mailpitCode(EMAIL, since));
    await page.locator('form[action$="/login/code"] button[type=submit]').click();
    try {
        await page.waitForURL(/\/b\//, { timeout: 15000 });
    } catch (e) {
        const alert = await page.locator('[role=alert], .field-error, .banner').allInnerTexts();
        throw new Error(`Login did not reach /b/… (still ${page.url()}): ${alert.join(' | ')}`);
    }
    console.log(`logged in as ${EMAIL} → ${page.url()}`);

    for (const [name, path] of residentPages) {
        await audit(name, path, results);
    }
} finally {
    await context.close();
    await rm(profile, { recursive: true, force: true });
}

const below = results.filter((r) => r.score < MIN);
const summary = { min: MIN, base: BASE, ranAt: new Date().toISOString(), lighthouse: 'mobile (default profile)', results };
await writeFile(join(OUT, 'summary.json'), JSON.stringify(summary, null, 2));
console.log(`\n${results.length} pages, lowest ${Math.min(...results.map((r) => r.score))}, ${below.length} below ${MIN}. Reports: ${OUT}/`);
process.exit(below.length ? 1 : 0);
