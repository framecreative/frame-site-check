// Crawls the testbed site once per account (front end or control panel), then writes one HTML report:
// problem pages first, a screenshot of every page, unreached routes and seeded-data gaps.
//
//   node crawl.js <config.json>

import { chromium } from 'playwright';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';

const config = JSON.parse(await readFile(process.argv[2], 'utf8'));
const crawl = config.crawl ?? {};
const base = new URL(config.baseUrl);
const maxPages = crawl.max_pages ?? 150;
const perPattern = crawl.per_pattern ?? 3;
const concurrency = crawl.concurrency ?? 3;
const toastSelector = crawl.error_toast_selector ?? '[data-sonner-toast][data-type="error"], .toast-error';

const DEFAULT_SKIP = [
    'logout', '/login$', '/register', '/forgot', '/reset-password', '/verify-email', '/delete', 'download',
    'export', '/pdf', '/certificate', 'impersonat', '\\.(pdf|zip|csv|xlsx?|docx?|jpe?g|png|gif|webp|svg|mp4|mp3)$',
];
const skip = [...DEFAULT_SKIP, ...(crawl.skip ?? [])].map((pattern) => new RegExp(pattern, 'i'));

const cpPath = crawl.cp_path ?? '/admin';
const login = {
    frontend: { path: '/login', email: '#email', password: '#password', ...(crawl.login?.frontend ?? {}) },
    cp: { path: `${cpPath}/auth/login`, email: 'input[name="email"]', password: 'input[name="password"]', ...(crawl.login?.cp ?? {}) },
};

await mkdir(path.join(config.outDir, 'shots'), { recursive: true });

// /course/a, /course/b … count as /course/* so only a few pages of each kind are visited.
const patternOf = (url) =>
    url.pathname
        .split('/')
        .filter(Boolean)
        .map((segment, i) => (i > 0 && /[-\d]|^.{21,}$/.test(segment) ? '*' : segment))
        .join('/') || '/';

const browser = await chromium.launch();
const results = [];
let shotIndex = 0;

for (const account of config.accounts) {
    const scope = account.guard === 'cp' ? 'cp' : 'frontend';
    const form = login[scope];
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });

    const page = await context.newPage();
    await page.goto(new URL(form.path, base).href);
    await page.fill(form.email, account.email);
    await page.fill(form.password, account.password);
    await page.press(form.password, 'Enter');
    try {
        await page.waitForURL((url) => !url.pathname.startsWith(form.path), { timeout: 20000 });
    } catch {
        results.push({ account: account.key, url: new URL(form.path, base).href, pattern: 'login', status: null,
            console: [`Login failed for ${account.email}: still on ${form.path} after submitting.`], failed: [], images: [], toasts: [] });
        await context.close();
        continue;
    }
    await page.close();

    const inScope = (url) => (scope === 'cp' ? url.pathname.startsWith(cpPath) : !url.pathname.startsWith(cpPath));
    const normalise = (href, from) => {
        try {
            const url = new URL(href, from);
            if (url.origin !== base.origin || !inScope(url)) return null;
            url.hash = '';
            url.search = '';
            return skip.some((re) => re.test(url.pathname)) ? null : url;
        } catch {
            return null;
        }
    };

    // An account can add its own start pages, for areas only reachable through iframes or scripted navigation.
    const starts = [
        ...(scope === 'cp' ? crawl.cp_start ?? [`${cpPath}/dashboard`] : crawl.start ?? ['/']),
        ...(account.start ?? []),
    ];
    const queue = starts.map((p) => new URL(p, base));
    const seen = new Set(queue.map((u) => u.href));
    const counts = new Map();
    let started = 0;
    let finished = 0;

    const visit = async (url) => {
        const tab = await context.newPage();
        const result = { account: account.key, url: url.href, pattern: patternOf(url), status: null,
            console: [], failed: [], images: [], toasts: [], shot: null };

        tab.on('console', (msg) => msg.type() === 'error' && result.console.push(msg.text()));
        tab.on('pageerror', (err) => result.console.push(`Uncaught: ${err.message}`));
        tab.on('requestfailed', (req) => {
            const error = req.failure()?.errorText ?? 'failed';
            if (error !== 'net::ERR_ABORTED') result.failed.push(`${error} ${req.url()}`);
        });
        tab.on('response', (res) => res.status() >= 400 && result.failed.push(`${res.status()} ${res.url()}`));

        try {
            const response = await tab.goto(url.href, { waitUntil: 'load', timeout: 45000 });
            result.status = response?.status() ?? null;
            await tab.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

            await tab.evaluate(async () => {
                for (let y = 0; y < document.body.scrollHeight; y += 600) {
                    window.scrollTo(0, y);
                    await new Promise((r) => setTimeout(r, 100));
                }
                window.scrollTo(0, 0);
            });
            await tab.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

            result.images = await tab.$$eval('img', (imgs) =>
                imgs.filter((img) => (img.currentSrc || img.getAttribute('src')) && img.complete && img.naturalWidth === 0)
                    .map((img) => img.currentSrc || img.getAttribute('src')));
            result.toasts = await tab.$$eval(toastSelector, (els) => els.map((el) => el.textContent.trim())).catch(() => []);

            for (const href of await tab.$$eval('a[href]', (as) => as.map((a) => a.getAttribute('href')))) {
                const next = normalise(href, url);
                if (next && !seen.has(next.href)) {
                    seen.add(next.href);
                    queue.push(next);
                }
            }

            result.shot = `shots/${String(++shotIndex).padStart(4, '0')}.jpg`;
            await tab.screenshot({ path: path.join(config.outDir, result.shot), fullPage: true, type: 'jpeg', quality: 60 });
        } catch (err) {
            result.console.push(`Crawler: ${err.message.split('\n')[0]}`);
        } finally {
            await tab.close();
        }

        return result;
    };

    const worker = async () => {
        while (started < maxPages) {
            const url = queue.shift();
            if (!url) {
                if (finished === started) return;
                await new Promise((r) => setTimeout(r, 200));
                continue;
            }
            const pattern = patternOf(url);
            if ((counts.get(pattern) ?? 0) >= perPattern) continue;
            counts.set(pattern, (counts.get(pattern) ?? 0) + 1);

            started++;
            const result = await visit(url);
            finished++;
            results.push(result);
            console.log(`[${account.key}] ${result.status ?? '---'} ${url.pathname}${isBroken(result) ? ' ✗' : ''}`);
        }
    };

    await Promise.all(Array.from({ length: concurrency }, worker));
    await context.close();
}

await browser.close();

function isBroken(r) {
    return !r.status || r.status >= 400 || r.console.length || r.failed.length || r.images.length || r.toasts.length;
}

// Route coverage: GET routes no crawled page matched, which usually means no seeded data links to them.
const routes = JSON.parse(await readFile(config.routes, 'utf8').catch(() => '[]'));
const visited = results.map((r) => new URL(r.url).pathname);
const routeRegex = (uri) =>
    new RegExp('^/' + uri.replace(/^\//, '')
        .replace(/\/\{[^}]+\?\}/g, '(?:/[^/]+)?')
        .replace(/\{[^}]+\}/g, '[^/]+') + '/?$');
const unreached = routes
    .filter((route) => route.method.includes('GET') && !skip.some((re) => re.test('/' + route.uri)))
    .filter((route) => !route.uri.startsWith('_') && !route.uri.startsWith('api/'))
    .filter((route) => !visited.some((p) => routeRegex(route.uri === '/' ? '' : route.uri).test(p)))
    .map((route) => route.uri);

const freshness = JSON.parse(await readFile(config.freshness, 'utf8').catch(() => '{}'));

results.sort((a, b) => Number(isBroken(b)) - Number(isBroken(a)) || a.url.localeCompare(b.url));
const broken = results.filter(isBroken);

const escape = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
const list = (title, items) => (items?.length ? `<h4>${title}</h4><ul>${items.map((i) => `<li>${escape(i)}</li>`).join('')}</ul>` : '');
const section = (title, items, empty) =>
    `<section><h2>${title} (${items?.length ?? 0})</h2>${items?.length ? `<ul>${items.map((i) => `<li>${escape(i)}</li>`).join('')}</ul>` : `<p class="meta">${empty}</p>`}</section>`;

const html = `<!doctype html>
<meta charset="utf-8">
<title>Testbed report</title>
<style>
    body { font: 14px/1.4 system-ui, sans-serif; margin: 24px; color: #1a1a1a; background: #fff; }
    section { margin-bottom: 24px; }
    .card { border: 1px solid #ddd; border-radius: 6px; margin-bottom: 16px; padding: 12px; display: grid; grid-template-columns: 240px 1fr; gap: 16px; }
    .card.bad { border-color: #d33; background: #fff5f5; }
    .card img { width: 240px; max-height: 360px; object-fit: cover; object-position: top; border: 1px solid #ccc; }
    h3 { margin: 0 0 4px; font-size: 14px; word-break: break-all; }
    h4 { margin: 8px 0 2px; font-size: 13px; }
    ul { margin: 0; padding-left: 18px; font-family: ui-monospace, monospace; font-size: 12px; word-break: break-all; }
    .meta { color: #666; }
</style>
<h1>Testbed report</h1>
<p>${results.length} pages crawled, <strong>${broken.length} with problems</strong>. ${new Date().toLocaleString('en-AU')}</p>
${section('Routes no page reached', unreached, 'Every GET route was reached.')}
${section('Tables with no seeded rows', freshness.emptyTables, 'None.')}
${section('Columns null in every seeded row', freshness.nullColumns, 'None.')}
<h2>Pages</h2>
${results
    .map((r) => `<div class="card ${isBroken(r) ? 'bad' : ''}">
    <div>${r.shot ? `<a href="${r.shot}" target="_blank"><img src="${r.shot}" loading="lazy"></a>` : 'No screenshot'}</div>
    <div>
        <h3>${escape(r.url)}</h3>
        <div class="meta">${escape(r.account)} · status ${r.status ?? 'none'} · pattern ${escape(r.pattern)}</div>
        ${list('Error toasts', r.toasts)}
        ${list('Broken images', r.images)}
        ${list('Console errors', r.console)}
        ${list('Failed requests', r.failed)}
    </div>
</div>`)
    .join('\n')}`;

await writeFile(path.join(config.outDir, 'index.html'), html);
await writeFile(path.join(config.outDir, 'results.json'), JSON.stringify({ results, unreached, freshness }, null, 2));

console.log(`\n${results.length} pages, ${broken.length} with problems, ${unreached.length} routes not reached.`);
process.exit(broken.length ? 1 : 0);
