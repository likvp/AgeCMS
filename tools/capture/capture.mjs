#!/usr/bin/env node
// AgeCMS capture: opens a site in Chrome like a visitor, saves every page with all its files and writes a package
// (folder and, with --zip, a zip) that the importer of AgeCMS turns into pages that look exactly like the source.
// Run on your own computer; the site on the server does not need a browser. See README.md.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { parseArgs, Log, sleep, SENTINEL, FORMAT, TOOL_VERSION, pageKey, oldPath, sameSite, bareHost, parseRobots, humanBytes, sha1 } from './lib/util.mjs';
import { Store } from './lib/store.mjs';
import { TRACKER_URLS, TRACKER_INLINE, ALLOWED_FRAME_PREFIXES, trackerLabel } from './lib/trackers.mjs';
import { snapshotDom, transform } from './lib/transform.mjs';
import { findChrome, launch, makeContext, openPage, settle, idle, shot, compareShots, fetchFull, makeUserAgent, mobileUserAgent } from './lib/browser.mjs';
import { serveFolder, servePackage } from './lib/serve.mjs';
import { zipFolder } from './lib/zip.mjs';

const HELP = `AgeCMS capture ${TOOL_VERSION}

Использование:
  node capture.mjs <адрес сайта | папка | zip> [параметры]

  --out=ПАПКА          куда положить пакет (по умолчанию ./capture-<сайт>)
  --zip[=ФАЙЛ]         упаковать пакет в zip (для загрузки в админку AgeCMS)
  --limit=N            сколько страниц взять (по умолчанию 50)
  --delay=МС           пауза между страницами (по умолчанию 800, не меньше 500 для чужих сайтов)
  --mode=auto|source|dom  что сохранять: исходный HTML со скриптами (source) или итоговый DOM без скриптов (dom); auto выбирает сам
  --pages=/a,/b        только эти страницы (главная берётся всегда)
  --exclude=РЕГВЫР     не брать адреса, подходящие под выражение
  --no-robots          не смотреть robots.txt (только для своего сайта)
  --no-mobile          не открывать телефонную версию (быстрее, но файлы только для компьютера)
  --no-verify          не сверять копию с оригиналом
  --no-phone-variant   не делать отдельный файл для телефона у страниц, которые скрипты собирают под ширину экрана
  --max-file-mb=N      самый большой файл (по умолчанию 30)
  --max-total-mb=N     предел размера пакета (по умолчанию 500)
  --chrome=ПУТЬ        Chrome или Chromium (иначе ищется сам)
  --cache=ПАПКА        кэш ответов сайта на диске (по умолчанию ~/.cache/agecms-capture), --no-cache отключает
  --keep-work          оставить снимки и рабочие файлы (.work) рядом с пакетом
  --headed             показывать окно браузера
  --quiet              меньше сообщений
`;

const { opts, positional } = parseArgs(process.argv.slice(2));
if (opts.help || opts.h || positional.length === 0) {
  process.stdout.write(HELP);
  process.exit(positional.length === 0 && !opts.help ? 1 : 0);
}

const log = new Log(null, !opts.quiet);
const startedAt = Date.now();
const LIMIT = Math.max(1, Math.min(2000, parseInt(opts.limit || '50', 10)));
const DELAY = Math.max(0, parseInt(opts.delay ?? '800', 10));
const MAX_FILE = Math.max(1, parseFloat(opts['max-file-mb'] || '30')) * 1048576;
const MAX_TOTAL = Math.max(10, parseFloat(opts['max-total-mb'] || '500')) * 1048576;
const MODE = ['auto', 'source', 'dom'].includes(opts.mode) ? opts.mode : 'auto';
const ROBOTS = !opts['no-robots'];
const MOBILE = !opts['no-mobile'];
const VERIFY = !opts['no-verify'];
const PHONE_VARIANT = !opts['no-phone-variant'];
const EXCLUDE = typeof opts.exclude === 'string' ? new RegExp(opts.exclude, 'i') : null;
const ONLY = typeof opts.pages === 'string' ? opts.pages.split(',').map((s) => s.trim()).filter(Boolean) : null;

let cleanup = [];
async function main() {
  // ---- source -----------------------------------------------------------------------------------------------
  let input = positional[0];
  let kind = 'url';
  let siteUrl;
  let tmpDir = null;
  if (fs.existsSync(input)) {
    let dir = input;
    if (fs.statSync(input).isFile()) {
      if (!/\.zip$/i.test(input)) throw new Error('Файл не похож на архив zip: ' + input);
      tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'agecms-src-'));
      try { execFileSync('unzip', ['-q', '-o', input, '-d', tmpDir]); } catch { throw new Error('Не удалось распаковать zip (нужна команда unzip). Распакуйте архив сами и укажите папку.'); }
      dir = tmpDir;
      const entries = fs.readdirSync(tmpDir).filter((n) => !n.startsWith('.') && n !== '__MACOSX');
      if (entries.length === 1 && fs.statSync(path.join(tmpDir, entries[0])).isDirectory()) dir = path.join(tmpDir, entries[0]);
      kind = 'zip';
    } else kind = 'folder';
    const srv = await serveFolder(dir);
    cleanup.push(() => srv.close());
    siteUrl = srv.url + '/';
    log.info(`Папка ${dir} показывается Chrome на ${srv.url}`);
  } else {
    if (!/^https?:\/\//i.test(input)) input = 'https://' + input;
    siteUrl = new URL(input).href;
  }
  const origin = new URL(siteUrl).origin;
  const host = new URL(siteUrl).host;
  const slugHost = host.replace(/[^A-Za-z0-9.-]+/g, '-');
  const out = path.resolve(typeof opts.out === 'string' ? opts.out : `capture-${slugHost}`);
  const work = path.join(out, '.work');
  fs.rmSync(out, { recursive: true, force: true });
  fs.mkdirSync(path.join(out, 'pages'), { recursive: true });
  fs.mkdirSync(work, { recursive: true });
  const cacheDir = opts['no-cache'] ? null : (typeof opts.cache === 'string' ? opts.cache : path.join(os.homedir(), '.cache', 'agecms-capture', slugHost));
  const store = new Store(path.join(out, 'files'), { cacheDir: kind === 'url' ? cacheDir : null, maxFileBytes: MAX_FILE, maxTotalBytes: MAX_TOTAL });

  // ---- browser ----------------------------------------------------------------------------------------------
  const chromePath = findChrome(typeof opts.chrome === 'string' ? opts.chrome : null);
  if (!chromePath) throw new Error('Не найден Google Chrome или Chromium. Установите Chrome или укажите путь: --chrome="/путь/к/chrome".');
  const browser = await launch(chromePath, !opts.headed);
  cleanup.push(() => browser.close());
  const version = browser.version();
  const UA = makeUserAgent(version);
  const shared = { blocked: new Set(), media: new Set() };
  const desktopCtx = await makeContext(browser, 'desktop', { store, log, state: shared, userAgent: UA });
  const mobileCtx = MOBILE ? await makeContext(browser, 'mobile', { store, log, state: shared, userAgent: mobileUserAgent(UA) }) : null;
  const tool = await browser.newPage();
  await tool.goto('about:blank');
  log.info(`Chrome ${version}, сайт ${siteUrl}, страниц не больше ${LIMIT}, режим ${MODE}`);

  // ---- robots.txt, sitemap ----------------------------------------------------------------------------------
  let robots = { allowed: () => true, sitemaps: [] };
  if (kind === 'url' && ROBOTS) {
    const r = await fetchFull(origin + '/robots.txt', { maxBytes: 512000, userAgent: UA });
    if (r.ok && !/^\s*<(!doctype|html)/i.test(r.body.toString('utf8', 0, 200))) robots = parseRobots(r.body.toString('utf8'));
    else log.info('robots.txt не найден: ограничений нет');
  }
  const blockedPath = /\/(wp-admin|wp-login\.php|wp-json|xmlrpc\.php|cdn-cgi|bitrix\/admin|administrator|logout|cart|checkout|basket|my-account|personal|auth|login|register|search|feed)(\/|\?|$)|[?&](replytocom|add-to-cart|logout|action=logout|print=|share=)|\.(pdf|jpe?g|png|gif|webp|svg|avif|zip|rar|docx?|xlsx?|pptx?|mp[34]|webm|xml|json|css|js|ico|woff2?|txt)(\?|$)/i;
  const pageAllowed = (u) => {
    let x;
    try { x = new URL(u); } catch { return false; }
    if (!/^https?:$/.test(x.protocol) || !sameSite(x.href, siteUrl)) return false;
    if (blockedPath.test(x.pathname + x.search)) return false;
    if (EXCLUDE && EXCLUDE.test(x.pathname + x.search)) return false;
    if (ONLY && x.pathname !== '/' && !ONLY.includes(x.pathname.replace(/\/$/, '') || '/') && !ONLY.includes(x.pathname)) return false;
    if (ROBOTS && !robots.allowed(x.pathname + x.search)) return false;
    return true;
  };
  const queue = [siteUrl];
  const seen = new Set([pageKey(siteUrl)]);
  const enqueue = (u) => {
    let key;
    try { key = pageKey(u); } catch { return; }
    if (seen.has(key) || !pageAllowed(u)) return;
    seen.add(key);
    queue.push(u);
  };
  // Pages named with --pages are taken even when no link on the home page leads to them.
  if (ONLY) for (const p of ONLY) enqueue(origin + (p.startsWith('/') ? p : '/' + p));
  if (kind === 'url' && !(ONLY && ONLY.length)) {
    const sitemapUrls = new Set(robots.sitemaps);
    sitemapUrls.add(origin + '/sitemap.xml');
    const done = new Set();
    const todo = [...sitemapUrls];
    const found = [];
    while (todo.length && done.size < 8) {
      const sm = todo.shift();
      if (done.has(sm)) continue;
      done.add(sm);
      const r = await fetchFull(sm, { maxBytes: 8388608, userAgent: UA });
      await sleep(Math.min(DELAY, 400));
      if (!r.ok) continue;
      let xml = r.body.toString('utf8');
      if (r.body[0] === 0x1f && r.body[1] === 0x8b) { try { xml = (await import('node:zlib')).gunzipSync(r.body).toString('utf8'); } catch { continue; } }
      for (const m of xml.matchAll(/<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)/g)) {
        const loc = m[1].replace(/&amp;/g, '&');
        if (/\.xml(\.gz)?(\?|$)/i.test(loc)) todo.push(loc); else found.push(loc);
      }
    }
    for (const u of found) if (sameSite(u, siteUrl)) enqueue(u);
    if (found.length) log.info(`В карте сайта ${found.length} адресов`);
  }

  // ---- pass 1: open pages -----------------------------------------------------------------------------------
  const pages = [];
  const failedPages = [];
  let n = 0;
  while (queue.length && pages.length < LIMIT) {
    const url = queue.shift();
    n++;
    let res;
    try {
      res = await openPage(desktopCtx, url);
    } catch (e) {
      failedPages.push({ url, why: String(e.message || e).split('\n')[0].slice(0, 160) });
      log.warn(`Страница не открылась: ${url} (${failedPages.at(-1).why})`);
      continue;
    }
    const { page, status, raw, finalUrl, headers } = res;
    const ct = headers['content-type'] || '';
    if (status >= 400 || (ct && !/html|xml/i.test(ct)) || raw === '') {
      failedPages.push({ url, why: status >= 400 ? 'ответ ' + status : 'не страница (' + ct.slice(0, 40) + ')' });
      log.warn(`Пропуск ${url}: ${failedPages.at(-1).why}`);
      await page.close();
      continue;
    }
    if (!sameSite(finalUrl, siteUrl)) {
      failedPages.push({ url, why: 'ведёт на другой сайт: ' + finalUrl });
      await page.close();
      continue;
    }
    const fk = pageKey(finalUrl);
    const dup = pages.find((p) => p.key === fk);
    if (dup) {
      dup.aliases.add(oldPath(url));
      await page.close();
      continue;
    }
    seen.add(fk);
    await settle(page);
    await idle(desktopCtx);
    const id = pages.length + 1;
    const tag = String(id).padStart(4, '0');
    const dom = await page.evaluate(snapshotDom);
    const links = await page.evaluate(() => Array.from(document.querySelectorAll('a[href]')).map((a) => a.href));
    const png = await shot(page);
    fs.writeFileSync(path.join(work, `${tag}-desktop.png`), png);
    fs.writeFileSync(path.join(work, `${tag}-raw.html`), raw);
    fs.writeFileSync(path.join(work, `${tag}-dom.html`), dom);
    await page.close();
    const rec = { id, tag, url, finalUrl, key: fk, path: oldPath(finalUrl), aliases: new Set(url === finalUrl ? [] : [oldPath(url)]), status, raw, dom, platform: detectPlatform(raw, headers), rawTextLen: visibleLen(raw), domTextLen: 0, shots: { desktop: png } };
    rec.aliases.delete(rec.path);
    pages.push(rec);
    log.info(`[${id}] ${finalUrl} (${rec.platform}, файлов ${Object.keys(store.map).length}, ${humanBytes(store.total)})`);
    for (const l of links) enqueue(l);
    // mobile
    if (mobileCtx) {
      try {
        const m = await openPage(mobileCtx, finalUrl);
        await settle(m.page);
        await idle(mobileCtx);
        rec.shots.mobile = await shot(m.page);
        fs.writeFileSync(path.join(work, `${tag}-mobile.png`), rec.shots.mobile);
        rec.domMobile = await m.page.evaluate(snapshotDom);
        await m.page.close();
      } catch (e) {
        log.warn(`Телефонная версия ${finalUrl} не открылась: ${String(e.message || e).split('\n')[0]}`);
      }
    }
    if (queue.length && pages.length < LIMIT) await sleep(Math.max(kind === 'url' ? 500 : 0, DELAY));
  }
  if (pages.length === 0) throw new Error('Не удалось открыть ни одной страницы: ' + (failedPages[0] ? failedPages[0].url + ' (' + failedPages[0].why + ')' : siteUrl));
  const leftover = queue.length;

  // media: the browser sees them in ranges, take whole files
  for (const u of shared.media) {
    if (store.has(u)) continue;
    await fetchInto(u, siteUrl);
  }
  async function fetchInto(u, referer) {
    if (store.has(u) || store.failed.has(u)) return;
    const cached = store.cacheGet(u);
    let r;
    if (cached && cached.status === 200) r = { ok: true, body: cached.body, contentType: cached.contentType, finalUrl: u };
    else {
      r = await fetchFull(u, { maxBytes: MAX_FILE, userAgent: UA, referer });
      if (r.ok) store.cachePut(u, 200, { 'content-type': r.contentType }, r.body);
      await sleep(120);
    }
    if (!r.ok) {
      store.failed.add(u);
      store.skipped.push({ url: u, why: r.why || 'ответ ' + r.status });
      return;
    }
    const alias = r.finalUrl && r.finalUrl !== u ? [r.finalUrl] : [];
    store.put(u, r.body, r.contentType, alias);
  }
  async function fetchMany(urls, referer) {
    const list = [...new Set(urls)].filter((u) => !store.has(u) && !store.failed.has(u));
    let i = 0;
    const worker = async () => { while (i < list.length) { const u = list[i++]; await fetchInto(u, referer); } };
    await Promise.all(Array.from({ length: Math.min(4, list.length) }, worker));
  }

  // ---- pass 2: build pages ----------------------------------------------------------------------------------
  const T = (kindName, text, baseUrl, extra = {}) => tool.evaluate(transform, {
    kind: kindName, text, baseUrl, map: store.map, failed: [...store.failed], sentinel: SENTINEL, siteHost: bareHost(host),
    trackerUrls: TRACKER_URLS, trackerInline: TRACKER_INLINE, framePrefixes: ALLOWED_FRAME_PREFIXES, mode: 'source', keepScripts: false, collectLinks: false, ...extra,
  });
  const cssOriginal = new Map();
  async function build(rec, mode) {
    const text = mode === 'dom' ? rec.dom : rec.raw;
    let r;
    for (let round = 0; round < 4; round++) {
      r = await T('html', text, rec.finalUrl, { mode });
      if (r.missing.length === 0) break;
      await fetchMany(r.missing, rec.finalUrl);
    }
    return r;
  }
  function write(rec, mode, r) {
    const doc = '<!DOCTYPE html>\n' + r.html + '\n';
    const file = `pages/${rec.tag}.html`;
    fs.writeFileSync(path.join(out, file), doc);
    rec.file = file;
    rec.mode = mode;
    rec.result = r;
  }
  for (const rec of pages) rec.domTextLen = (await T('html', rec.dom, rec.finalUrl, { mode: 'dom' })).textLength;
  for (const rec of pages) {
    rec.mode = chooseMode(rec);
    write(rec, rec.mode, await build(rec, rec.mode));
  }

  async function buildPhone(rec) {
    try {
      let r;
      for (let round = 0; round < 4; round++) {
        r = await T('html', rec.domMobile, rec.finalUrl, { mode: 'dom' });
        if (r.missing.length === 0) break;
        await fetchMany(r.missing, rec.finalUrl);
      }
      const file = `pages/${rec.tag}.m.html`;
      fs.writeFileSync(path.join(out, file), '<!DOCTYPE html>\n' + r.html + '\n');
      rec.mobileFile = file;
      await finalizeAssets();
      return true;
    } catch (e) {
      log.warn(`Версию для телефона ${rec.finalUrl} собрать не удалось: ${String(e.message || e).split('\n')[0]}`);
      return false;
    }
  }

  fs.mkdirSync(path.join(out, 'files', '_blocked'), { recursive: true });
  fs.writeFileSync(path.join(out, 'files', '_blocked', 'empty.js'), '/* Removed on import: a counter or a widget of another service. */\n');

  // ---- files: CSS and JS addresses ------------------------------------------------------------------------
  const jsDone = new Set();
  async function finalizeAssets() {
    for (let round = 0; round < 5; round++) {
      const missing = new Set();
      for (const [local, inf] of store.info) {
        if (!/\.css$/i.test(local) && !/css/.test(inf.type)) continue;
        if (!cssOriginal.has(local)) cssOriginal.set(local, store.read(local).toString('utf8'));
        const r = await T('css', cssOriginal.get(local), inf.url);
        store.write(local, r.text);
        for (const m of r.missing) missing.add(m);
      }
      if (missing.size === 0) break;
      await fetchMany([...missing], siteUrl);
      for (const m of missing) if (!store.has(m)) store.failed.add(m);
    }
    for (const [local, inf] of store.info) {
      if (jsDone.has(local) || !(/\.m?js$/i.test(local) || /javascript/.test(inf.type))) continue;
      jsDone.add(local);
      const text = store.read(local).toString('utf8');
      if (!/https?:(\\?\/){2}/.test(text)) continue;
      const r = await T('js', text, inf.url);
      if (r.text !== text) store.write(local, r.text);
    }
  }
  await finalizeAssets();

  // ---- self-check ---------------------------------------------------------------------------------------------
  const verifyResults = new Map();
  async function verify(rec, phoneVariant = false) {
    const served = [{ pathKey: rec.path, file: rec.file }];
    if (rec.mobileFile) served.push({ pathKey: rec.path + '?ag_view=m', file: rec.mobileFile });
    const srv = await servePackage(out, served);
    const res = { desktop: null, mobile: null, console: [], external: [], failed: [] };
    try {
      for (const kindName of phoneVariant ? ['mobile'] : ['desktop', 'mobile']) {
        const orig = rec.shots[kindName];
        if (!orig) continue;
        const ctx = await makeVerifyContext(browser, kindName, UA);
        const p = await ctx.newPage();
        p.on('console', (m) => { if (m.type() === 'error' && res.console.length < 20) res.console.push(`${kindName}: ${m.text().slice(0, 200)}`); });
        p.on('pageerror', (e) => { if (res.console.length < 20) res.console.push(`${kindName}: JS ${String(e.message).slice(0, 200)}`); });
        p.on('requestfailed', (rq) => { const u = rq.url(); if (!u.startsWith('data:') && res.failed.length < 20) res.failed.push(`${kindName}: ${u.slice(0, 160)} (${rq.failure()?.errorText || ''})`); });
        p.on('request', (rq) => { if (rq.frame() !== p.mainFrame()) return; const u = rq.url(); if (/^https?:/.test(u) && !u.startsWith(srv.url) && res.external.length < 30 && !res.external.includes(u.slice(0, 160))) res.external.push(u.slice(0, 160)); });
        await p.goto(srv.url + rec.path + (phoneVariant ? '?ag_view=m' : ''), { waitUntil: 'load', timeout: 45000 }).catch(() => {});
        await p.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
        await settle(p).catch(() => {});
        const copy = await shot(p);
        fs.writeFileSync(path.join(work, `${rec.tag}-${kindName}-copy-${phoneVariant ? 'phone' : rec.mode}.png`), copy);
        res[kindName] = await compareShots(tool, orig, copy);
        await ctx.close();
      }
    } finally {
      srv.close();
    }
    return res;
  }
  if (VERIFY) {
    for (const rec of pages) {
      let v = await verify(rec);
      verifyResults.set(rec.id, v);
      const score = scoreOf(v);
      log.info(`Сверка [${rec.id}] ${rec.mode}: компьютер ${pct(v.desktop)}, телефон ${pct(v.mobile)}`);
      if (MODE === 'auto' && score < 0.9) {
        const alt = rec.mode === 'source' ? 'dom' : 'source';
        const keep = { file: rec.file, mode: rec.mode, result: rec.result };
        write(rec, alt, await build(rec, alt));
        await finalizeAssets();
        const v2 = await verify(rec);
        log.info(`Сверка [${rec.id}] ${alt}: компьютер ${pct(v2.desktop)}, телефон ${pct(v2.mobile)}`);
        if (scoreOf(v2) > score + 0.01) {
          v = v2;
          verifyResults.set(rec.id, v2);
        } else {
          write(rec, keep.mode, keep.result);
          verifyResults.set(rec.id, v);
        }
      }
      // A page whose phone version is not the desktop page made narrower (the layout is built by scripts for the width of
      // the screen) gets a second file for phones: the page as the phone drew it. Narrow screens are sent to it by the page.
      const cur = verifyResults.get(rec.id);
      if (MOBILE && rec.domMobile && (cur.mobile ? cur.mobile.similarity < 0.9 : false) && PHONE_VARIANT) {
        const tryVariant = await buildPhone(rec);
        if (tryVariant) {
          const vm = await verify(rec, true);
          log.info(`Сверка [${rec.id}] версия для телефона: ${pct(vm.mobile)} (было ${pct(cur.mobile)})`);
          if (vm.mobile && vm.mobile.similarity > cur.mobile.similarity + 0.02) {
            verifyResults.set(rec.id, { ...cur, mobile: vm.mobile, phone_variant: true });
          } else {
            fs.rmSync(path.join(out, rec.mobileFile), { force: true });
            rec.mobileFile = '';
          }
        }
      }
    }
  }

  // ---- manifest -------------------------------------------------------------------------------------------------
  const platform = mostCommon(pages.map((p) => p.platform));
  const fileList = [];
  for (const [local, inf] of store.info) fileList.push({ path: 'files/' + local, url: inf.url, type: inf.type, bytes: inf.bytes, sha256: inf.sha256 });
  const toSent = (abs) => {
    const key = abs ? abs.split('#')[0] : '';
    return key && store.map[key] ? SENTINEL + store.map[key] : abs || '';
  };
  const trackerCounts = {};
  const manifestPages = pages.map((rec) => {
    const r = rec.result;
    const v = verifyResults.get(rec.id);
    for (const t of r.trackers) trackerCounts[t] = (trackerCounts[t] || 0) + 1;
    return {
      id: rec.id,
      url: rec.finalUrl,
      path: rec.path,
      aliases: [...rec.aliases],
      file: rec.file,
      mobile_file: rec.mobileFile || '',
      mode: rec.mode,
      platform: rec.platform,
      status: rec.status,
      title: r.meta.title,
      description: r.meta.description,
      og_title: r.meta.og_title,
      og_image: toSent(r.meta.og_image),
      canonical: r.meta.canonical,
      noindex: r.meta.noindex,
      lang: r.meta.lang,
      favicon: toSent(r.meta.favicon),
      text_length: r.textLength,
      forms: r.forms,
      trackers: r.trackers,
      notes: r.notes.slice(0, 50),
      verify: v ? { desktop: v.desktop && round2(v.desktop.similarity), mobile: v.mobile && round2(v.mobile.similarity), console: v.console, external: v.external, failed: v.failed } : null,
    };
  });
  const totalBytes = store.total;
  const manifest = {
    format: FORMAT,
    tool: { name: 'agecms-capture', version: TOOL_VERSION, chrome: version },
    created_at: new Date().toISOString(),
    source: { url: siteUrl, origin, host, kind, input: kind === 'url' ? siteUrl : path.basename(positional[0]) },
    platform,
    mode: MODE,
    viewports: { desktop: [1280, 900], mobile: MOBILE ? [390, 844] : null },
    site: { title: pages[0].result.meta.title, lang: pages[0].result.meta.lang, favicon: toSent(pages[0].result.meta.favicon) },
    pages: manifestPages,
    files: fileList,
    totals: { pages: pages.length, files: fileList.length, bytes: totalBytes },
    trackers_removed: trackerCounts,
    blocked_requests: [...shared.blocked].slice(0, 100),
    skipped_files: store.skipped.slice(0, 200),
    failed_pages: failedPages.slice(0, 100),
    more_pages_not_taken: leftover,
    limits: { pages: LIMIT, delay_ms: DELAY, max_file_mb: MAX_FILE / 1048576, max_total_mb: MAX_TOTAL / 1048576 },
    seconds: Math.round((Date.now() - startedAt) / 1000),
  };
  fs.writeFileSync(path.join(out, 'manifest.json'), JSON.stringify(manifest, null, 1));
  fs.writeFileSync(path.join(out, 'capture.log'), log.text());
  await tool.close();
  await browser.close();
  cleanup = cleanup.filter((f) => f.toString().indexOf('browser.close') < 0);
  if (!opts['keep-work']) fs.rmSync(work, { recursive: true, force: true });
  let zipPath = null;
  if (opts.zip) {
    zipPath = path.resolve(typeof opts.zip === 'string' ? opts.zip : out.replace(/\/$/, '') + '.zip');
    fs.rmSync(zipPath, { force: true });
    zipFolder(out, zipPath);
  }
  log.info(`Готово: ${pages.length} страниц, ${fileList.length} файлов, ${humanBytes(totalBytes)}${zipPath ? ', zip ' + humanBytes(fs.statSync(zipPath).size) : ''}. Пакет: ${out}`);
  if (leftover) log.warn(`Ещё ${leftover} адресов найдено, но лимит ${LIMIT} страниц. Увеличьте --limit.`);
  process.stdout.write(JSON.stringify({ out, zip: zipPath, pages: pages.length, files: fileList.length, bytes: totalBytes }) + '\n');
  if (tmpDir) fs.rmSync(tmpDir, { recursive: true, force: true });
  return 0;

  function chooseMode(rec) {
    if (MODE !== 'auto') return MODE;
    if (rec.platform === 'wix') return 'dom';
    if (rec.domTextLen > 300 && rec.rawTextLen < rec.domTextLen * 0.25) return 'dom';
    return 'source';
  }
}

async function makeVerifyContext(browser, kindName, ua) {
  const { VIEWPORTS } = await import('./lib/browser.mjs');
  const vp = VIEWPORTS[kindName];
  const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height }, deviceScaleFactor: kindName === 'mobile' ? 2 : 1, isMobile: vp.isMobile, hasTouch: vp.hasTouch, userAgent: kindName === 'mobile' ? mobileUserAgent(ua) : ua, locale: 'ru-RU', serviceWorkers: 'block' });
  return ctx;
}

const round2 = (x) => Math.round(x * 1000) / 1000;
const pct = (r) => (r ? Math.round(r.similarity * 100) + '%' : '-');
const scoreOf = (v) => {
  const a = [v.desktop, v.mobile].filter(Boolean).map((r) => r.similarity);
  return a.length ? a.reduce((x, y) => x + y, 0) / a.length : 1;
};
const mostCommon = (arr) => {
  const c = {};
  for (const a of arr) c[a] = (c[a] || 0) + 1;
  return Object.entries(c).sort((x, y) => y[1] - x[1])[0]?.[0] || 'unknown';
};
function visibleLen(html) {
  return html.replace(/<(script|style|noscript|template|svg)[\s\S]*?<\/\1>/gi, ' ').replace(/<!--[\s\S]*?-->/g, ' ').replace(/<[^>]+>/g, ' ').replace(/&[a-z#0-9]+;/gi, ' ').replace(/\s+/g, ' ').trim().length;
}
function detectPlatform(html, headers = {}) {
  const h = html.slice(0, 400000);
  if (/id="allrecords"|class="t-records"|tildacdn\.com|data-tilda-/i.test(h)) return 'tilda';
  if (/wixstatic\.com|wix-ui-santa|X-Wix|<meta name="generator" content="Wix/i.test(h) || headers['x-wix-request-id']) return 'wix';
  if (/data-wf-page|webflow\.com|\bw-nav\b|class="w-/i.test(h)) return 'webflow';
  if (/\/wp-content\/|\/wp-includes\/|name="generator" content="WordPress/i.test(h)) return 'wordpress';
  if (/\/bitrix\/(templates|js|cache)\/|BX\.message|bitrix_sessid/i.test(h)) return 'bitrix';
  if (/name="generator" content="Joomla|\/media\/jui\/|\/templates\/[a-z0-9_-]+\/css\//i.test(h)) return 'joomla';
  if (/MODX|modx\.com|\/assets\/(components|templates)\//i.test(h)) return 'modx';
  if (/catalog\/view\/theme\/|route=common\/home|opencart/i.test(h)) return 'opencart';
  if (/name="generator" content="Drupal|\/sites\/default\/files\//i.test(h)) return 'drupal';
  if (/hostcms/i.test(h)) return 'hostcms';
  return 'unknown';
}

main().then((code) => { for (const f of cleanup) { try { f(); } catch { /* ignore */ } } process.exit(code); }).catch(async (e) => {
  process.stderr.write('Ошибка: ' + (e && e.message || e) + '\n');
  if (process.env.AGECMS_CAPTURE_DEBUG) process.stderr.write(String(e && e.stack) + '\n');
  for (const f of cleanup) { try { await f(); } catch { /* ignore */ } }
  process.exit(1);
});
