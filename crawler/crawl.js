// Crawls the site-check site once per account (front end or control panel), then writes one HTML report:
// problem pages first, a screenshot of every page, unreached routes and seeded-data gaps.
//
//   node crawl.js <config.json>

import { chromium } from 'playwright';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { SourceMap } from 'node:module';
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
const admin = {
    targets: [],
    save_shortcut: 'Control+s',
    title_selector: 'main input[type="text"]:visible',
    ...(config.admin ?? {}),
};
const login = {
    frontend: { path: '/login', email: '#email', password: '#password', ...(crawl.login?.frontend ?? {}) },
    cp: { path: `${cpPath}/auth/login`, email: 'input[name="email"]', password: 'input[name="password"]', ...(crawl.login?.cp ?? {}) },
};

await mkdir(path.join(config.outDir, 'shots'), { recursive: true });

// Errors point at built bundles; the source maps the site check build writes map them back to the site's own files.
const sourceMaps = new Map();
const sourceMapFor = (fileUrl) => {
    if (!sourceMaps.has(fileUrl)) {
        sourceMaps.set(fileUrl, fetch(`${fileUrl}.map`)
            .then((res) => (res.ok ? res.json() : null))
            .then((payload) => (payload ? new SourceMap(payload) : null))
            .catch(() => null));
    }
    return sourceMaps.get(fileUrl);
};

const resolveFrames = async (text) => {
    const frames = [...text.matchAll(/(https?:\/\/[^\s()]+?\.js):(\d+):(\d+)/g)];
    let resolved = text;
    for (const [match, file, line, column] of frames) {
        const entry = (await sourceMapFor(file))?.findEntry(Number(line) - 1, Number(column) - 1);
        if (entry?.originalSource) {
            const source = entry.originalSource.replace(/^.*?(?=resources\/|node_modules\/|vendor\/)/, '');
            resolved = resolved.replace(match, `${source}:${entry.originalLine + 1}:${entry.originalColumn + 1}`);
        }
    }
    return resolved;
};

const STACK_FRAMES = 8;
// Some errors arrive with an empty stack, or a stack that doesn't repeat the message; always keep the message.
const errorEntry = (err) => {
    const stack = (err.stack || '').split('\n').filter((line) => line.trim().startsWith('at ')).slice(0, STACK_FRAMES);
    return [`Uncaught: ${err.message}`, ...stack].join('\n');
};
const consoleEntry = (msg) => {
    const { url, lineNumber, columnNumber } = msg.location();
    if (!url) return msg.text();
    return msg.text().startsWith('Failed to load resource')
        ? `${msg.text()}: ${url}`
        : `${msg.text()}\n    at ${url}:${lineNumber + 1}:${columnNumber + 1}`;
};

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
let failureIndex = 0;

for (const account of config.accounts) {
    const scope = account.guard === 'cp' ? 'cp' : 'frontend';
    const form = login[scope];
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const accountStart = results.length;
    // "▸" lines are the run's progress milestones; the skill's monitor passes only those on.
    console.log(`\n▸ Crawling as ${account.key} (${config.accounts.indexOf(account) + 1} of ${config.accounts.length})`);

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

        tab.on('console', (msg) => msg.type() === 'error' && result.console.push(consoleEntry(msg)));
        tab.on('pageerror', (err) => result.console.push(errorEntry(err)));
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

        result.console = await Promise.all(result.console.map(resolveFrames));

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

    const crawled = results.slice(accountStart);
    console.log(`▸ ${account.key}: ${crawled.length} pages, ${crawled.filter(isBroken).length} with problems`);

    if (scope === 'cp' && admin.targets.length) {
        console.log(`▸ Control panel save and create tests (${admin.targets.length} content types)`);
    }

    if (scope === 'cp') {
        for (const target of admin.targets) {
            for (const action of ['save', 'create']) {
                const targetPath = action === 'save' ? target.edit : target.create;
                if (!targetPath) continue;
                const result = await adminAction(context, account, target, action, new URL(targetPath, base));
                results.push(result);
                console.log(`[${account.key}] ${action} ${target.key} ${result.status ?? '---'}${result.skipped ? ' skipped' : result.denied ? ' denied' : isBroken(result) ? ' ✗' : ''}`);
            }
        }
        const actions = results.slice(accountStart).filter((r) => r.action);
        console.log(`▸ Control panel tests: ${actions.length} run, ${actions.filter(isBroken).length} failed`);
    }

    await context.close();
}

await browser.close();

// Saves an existing record unchanged, or creates one with only its title filled, through the control panel's save
// shortcut. A create the site's validation rejects is skipped rather than broken, with the fields it needed listed.
async function adminAction(context, account, target, action, url) {
    const tab = await context.newPage();
    const result = { account: account.key, url: url.href, pattern: `admin:${action}:${target.key}`, action,
        status: null, console: [], failed: [], images: [], toasts: [], validation: [], skipped: false, denied: false, shot: null };

    tab.on('console', (msg) => msg.type() === 'error' && result.console.push(consoleEntry(msg)));
    tab.on('pageerror', (err) => result.console.push(errorEntry(err)));

    try {
        if (target.elevate) await elevate(context, account);

        const response = await tab.goto(url.href, { waitUntil: 'load', timeout: 45000 });
        if ((response?.status() ?? 0) >= 400) {
            result.status = response.status();
            result.denied = result.status === 403;
            if (!result.denied) result.failed.push(`${result.status} ${url.href}`);
            return result;
        }
        await tab.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});

        const isSubmit = (request) => {
            const pathname = new URL(request.url()).pathname;
            return ['POST', 'PATCH'].includes(request.method())
                && pathname.startsWith(target.submits)
                && !/\/(preview|title-format|listing-api|actions)(\/|$)/.test(pathname);
        };

        // The form keeps its own keys (blueprint, site…); every field in the seeded payload replaces the form's value,
        // so a create needs nothing filled by hand. The merged body is what the failure file records.
        let sentBody = null;
        const direct = action === 'create' && target.submit_directly && target.payload;
        if (action === 'create' && !direct) {
            const title = tab.locator(admin.title_selector).first();
            if (await title.count()) await title.fill(`Site check ${target.label}`);

            if (target.payload) {
                await tab.route('**/*', async (route) => {
                    const request = route.request();
                    if (!isSubmit(request)) return route.fallback();
                    const form = JSON.parse(request.postData() || '{}');
                    sentBody = { ...form, ...target.payload };
                    return route.continue({ postData: JSON.stringify(sentBody) });
                });
            }
        }

        const submitted = tab.waitForResponse((res) => isSubmit(res.request()), { timeout: 20000 });
        if (direct) {
            // A create page with no save shortcut (a wizard): the seeded payload is posted from the page, as its form would.
            sentBody = target.payload;
            await cpFetch(tab, new URL(target.submits, config.baseUrl).href, sentBody);
        } else {
            await tab.keyboard.press(admin.save_shortcut);
        }
        const saved = await submitted;
        result.status = saved.status();

        if (result.status === 422) {
            const body = await saved.json().catch(() => ({}));
            result.validation = Object.entries(body.errors ?? {}).map(([field, messages]) => `${field}: ${[].concat(messages).join(' ')}`);
            // Without seeded values a create can only fill its title, so a 422 there means "needs more" rather than broken.
            result.skipped = action === 'create' && !target.payload;
        } else if (result.status === 403) {
            result.denied = true;
        } else if (result.status >= 400) {
            const body = await saved.json().catch(() => null);
            result.failed.push(`${result.status} ${saved.url()}${body?.message ? ` ${body.message}` : ''}`);
        }

        if (result.status >= 400 && !result.skipped && !result.denied) {
            result.submitted = await writeFailure(target, action, url, saved, sentBody);
        }

        await tab.waitForTimeout(1500);
        if (!result.skipped && !result.denied) {
            result.toasts = await tab.$$eval(toastSelector, (els) => els.map((el) => el.textContent.trim())).catch(() => []);
        }
        result.shot = `shots/${String(++shotIndex).padStart(4, '0')}.jpg`;
        await tab.screenshot({ path: path.join(config.outDir, result.shot), fullPage: true, type: 'jpeg', quality: 60 });
    } catch (err) {
        result.console.push(`Crawler: ${action} did not complete: ${err.message.split('\n')[0]}`);
    } finally {
        await tab.close();
    }

    result.console = await Promise.all(result.console.map(resolveFrames));

    return result;
}

// A JSON request from inside a control panel page, with the CSRF token the CP itself uses.
async function cpFetch(tab, url, body) {
    return tab.evaluate(async ({ url, body }) => {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': window.StatamicConfig?.csrfToken ?? '',
            },
            body: JSON.stringify(body),
        });
        return response.status;
    }, { url, body });
}

// Statamic asks for the password again before some pages (users, for one). Confirming it once elevates the session
// for every tab in this account's browser context.
async function elevate(context, account) {
    const tab = await context.newPage();
    try {
        await tab.goto(new URL(cpPath, config.baseUrl).href, { waitUntil: 'load', timeout: 45000 });
        await cpFetch(tab, new URL(`${cpPath}/elevated-session`, config.baseUrl).href, { password: account.password });
    } finally {
        await tab.close();
    }
}

// The exact data a failed save or create sent, and what came back, so the failure can be traced to its data.
// Rerunning with the report's seed rebuilds the same data to test a fix against.
async function writeFailure(target, action, url, response, sentBody = null) {
    const request = response.request();
    const parse = (text) => {
        try {
            return JSON.parse(text);
        } catch {
            return text;
        }
    };
    const file = `failures/${String(++failureIndex).padStart(3, '0')}-${action}-${target.key}.json`;

    await mkdir(path.join(config.outDir, 'failures'), { recursive: true });
    await writeFile(path.join(config.outDir, file), JSON.stringify({
        target: target.key,
        action,
        page: url.href,
        at: new Date().toISOString(),
        seed: config.seed ?? null,
        request: { method: request.method(), url: request.url(), body: sentBody ?? parse(request.postData() ?? '') },
        response: { status: response.status(), body: parse(await response.text().catch(() => '')) },
    }, null, 2));

    return file;
}

function isBroken(r) {
    if (r.skipped || r.denied) return false;
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
const adminResults = results.filter((r) => r.action);
const adminSummary = ['save', 'create'].map((action) => {
    const done = adminResults.filter((r) => r.action === action);
    return `${done.length} ${action === 'save' ? 'saves' : 'creates'} (${done.filter(isBroken).length} failed, `
        + `${done.filter((r) => r.skipped).length} skipped, ${done.filter((r) => r.denied).length} denied by the role)`;
}).join(', ');

const escape = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
const list = (title, items) => (items?.length ? `<h4>${title}</h4><ul>${items.map((i) => `<li>${escape(i)}</li>`).join('')}</ul>` : '');
const section = (title, items, empty) =>
    `<section><h2>${title} (${items?.length ?? 0})</h2>${items?.length ? `<ul>${items.map((i) => `<li>${escape(i)}</li>`).join('')}</ul>` : `<p class="meta">${empty}</p>`}</section>`;

const html = `<!doctype html>
<meta charset="utf-8">
<title>Site check report</title>
<style>
    body { font: 14px/1.4 system-ui, sans-serif; margin: 24px; color: #1a1a1a; background: #fff; }
    section { margin-bottom: 24px; }
    .card { border: 1px solid #ddd; border-radius: 6px; margin-bottom: 16px; padding: 12px; display: grid; grid-template-columns: 240px 1fr; gap: 16px; }
    .card.bad { border-color: #d33; background: #fff5f5; }
    .card img { width: 240px; max-height: 360px; object-fit: cover; object-position: top; border: 1px solid #ccc; }
    h3 { margin: 0 0 4px; font-size: 14px; word-break: break-all; }
    h4 { margin: 8px 0 2px; font-size: 13px; }
    ul { margin: 0; padding-left: 18px; font-family: ui-monospace, monospace; font-size: 12px; word-break: break-all; }
    li { white-space: pre-wrap; }
    .meta { color: #666; }
</style>
<h1>Site check report</h1>
<p>${results.length - adminResults.length} pages crawled, <strong>${broken.length} with problems</strong>. ${new Date().toLocaleString('en-AU')}</p>
<p>Control panel: ${adminResults.length ? adminSummary : 'no save or create tests ran.'}</p>
<p>Seed: <strong>${escape(config.seed ?? 'none')}</strong>${config.seed ? ` (rebuild this data with <code>site-check run --seed=${escape(config.seed)}</code>)` : ''}</p>
${section('Routes no page reached', unreached, 'Every GET route was reached.')}
${section('Tables with no seeded rows', freshness.emptyTables, 'None.')}
${section('Columns null in every seeded row', freshness.nullColumns, 'None.')}
<h2>Pages</h2>
${results
    .map((r) => `<div class="card ${isBroken(r) ? 'bad' : ''}">
    <div>${r.shot ? `<a href="${r.shot}" target="_blank"><img src="${r.shot}" loading="lazy"></a>` : 'No screenshot'}</div>
    <div>
        <h3>${escape(r.url)}</h3>
        <div class="meta">${escape(r.account)} · status ${r.status ?? 'none'} · ${r.action ? `${r.action}${r.skipped ? ' skipped' : ''}${r.denied ? ' denied by the role' : ''}` : 'pattern'} ${escape(r.pattern)}</div>
        ${r.submitted ? `<h4>Submitted data</h4><p><a href="${r.submitted}" target="_blank">${escape(r.submitted)}</a></p>` : ''}
        ${list(r.skipped ? 'Fields the create needed' : 'Validation errors', r.validation)}
        ${list('Error toasts', r.toasts)}
        ${list('Broken images', r.images)}
        ${list('Console errors', r.console)}
        ${list('Failed requests', r.failed)}
    </div>
</div>`)
    .join('\n')}`;

await writeFile(path.join(config.outDir, 'index.html'), html);
await writeFile(path.join(config.outDir, 'results.json'), JSON.stringify({ results, unreached, freshness }, null, 2));

console.log(`\n${results.length - adminResults.length} pages, ${broken.length} with problems, ${unreached.length} routes not reached.`);
console.log(`Control panel: ${adminResults.length ? adminSummary : 'no save or create tests ran.'}`);
process.exit(broken.length ? 1 : 0);
