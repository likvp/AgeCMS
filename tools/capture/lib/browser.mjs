// Chrome: start, open a page the way a visitor does (scroll, wait for pictures and fonts), collect the files it loads.
import fs from 'node:fs';
import { chromium } from 'playwright-core';
import { isTrackerUrl } from './trackers.mjs';
import { UA_SUFFIX, sleep } from './util.mjs';
import { safeGet, urlProblem, guardState } from './netguard.mjs';

export const VIEWPORTS = {
  desktop: { width: 1280, height: 900, deviceScaleFactor: 1, isMobile: false, hasTouch: false },
  mobile: { width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true },
};

const CHROME_PATHS = [
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  '/Applications/Chromium.app/Contents/MacOS/Chromium',
  '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser', '/snap/bin/chromium',
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
];

/** Path of an installed Chrome/Chromium, or null. Order: the path given, AGECMS_CHROME, CHROME_PATH, the usual places. */
export function findChrome(preferred) {
  if (typeof preferred === 'string' && preferred !== '' && !fs.existsSync(preferred)) {
    const e = new Error('Chrome не найден по указанному пути: ' + preferred);
    e.code = 'no_chrome';
    throw e;
  }
  for (const p of [preferred, process.env.AGECMS_CHROME, process.env.CHROME_PATH, ...CHROME_PATHS]) {
    if (p && fs.existsSync(p)) return p;
  }
  return null;
}

/** The environment Chrome gets: no secrets of the site or of the server, only what a browser needs to run. */
export function chromeEnv() {
  const keep = ['PATH', 'HOME', 'TMPDIR', 'TMP', 'TEMP', 'LANG', 'LC_ALL', 'LC_CTYPE', 'TZ', 'DISPLAY', 'XAUTHORITY', 'XDG_RUNTIME_DIR', 'FONTCONFIG_PATH', 'FONTCONFIG_FILE', 'SYSTEMROOT', 'WINDIR', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA', 'PLAYWRIGHT_BROWSERS_PATH'];
  const env = {};
  for (const k of keep) if (process.env[k] !== undefined) env[k] = process.env[k];
  return env;
}

const SANDBOX_ERROR = /sandbox|namespace|setuid|zygote|clone\(|CLONE_NEWUSER|user_namespace|Operation not permitted/i;

/**
 * Starts Chrome the way the capture needs it: its own temporary profile (Playwright makes one and removes it), the sandbox ON,
 * all traffic through the guard proxy, no secrets in its environment. Tries, in this order: the Chrome that was named or found,
 * the "chrome" channel of Playwright, the Chromium that Playwright keeps in PLAYWRIGHT_BROWSERS_PATH.
 * When the sandbox cannot start (a container, restricted user namespaces) and the process does not run as root, it starts
 * once more without the sandbox and says so in `browser.agInfo.warnings`.
 *
 * @param {object} o {chromePath, explicit, headless, proxy:{server,username,password}|null, sandbox:'auto'|'on', args:string[]}
 */
export async function launch(o = {}) {
  const headless = o.headless !== false;
  const args = ['--disable-blink-features=AutomationControlled', '--hide-scrollbars', '--force-color-profile=srgb', '--font-render-hinting=none', '--disable-dev-shm-usage',
    '--disable-quic', '--force-webrtc-ip-handling-policy=disable_non_proxied_udp', '--disable-background-networking', '--disable-component-update', '--disable-sync', '--no-first-run',
    '--no-default-browser-check', '--disable-extensions', '--disable-notifications', '--mute-audio', '--js-flags=--max-old-space-size=1024',
    '--disable-features=Translate,MediaRouter,OptimizationHints,DnsOverHttps', ...(o.args || [])];
  if (o.proxy) args.push('--host-resolver-rules=MAP * ~NOTFOUND , EXCLUDE 127.0.0.1');
  const attempts = [];
  if (o.chromePath) attempts.push({ via: 'path', executablePath: o.chromePath });
  if (!(o.explicit && o.chromePath)) {
    attempts.push({ via: 'channel', channel: 'chrome' });
    attempts.push({ via: 'playwright' });
  }
  const isRoot = typeof process.getuid === 'function' && process.getuid() === 0;
  let lastError = null;
  const errors = [];
  for (const a of attempts) {
    lastError = null;
    for (const sandbox of [true, false]) {
      if (sandbox === false && (o.sandbox === 'on' || !lastError || !SANDBOX_ERROR.test(String(lastError.message || lastError)))) break;
      if (sandbox === false && isRoot) {
        const e = new Error('Chrome не запускается без песочницы от имени root. Запустите захват от обычного пользователя (например, от пользователя веб-сервера).');
        e.code = 'root_no_sandbox';
        throw e;
      }
      try {
        const browser = await chromium.launch({
          headless, args, chromiumSandbox: sandbox, env: chromeEnv(), handleSIGINT: false, handleSIGTERM: false, handleSIGHUP: false, timeout: 40000,
          ...(a.executablePath ? { executablePath: a.executablePath } : {}), ...(a.channel ? { channel: a.channel } : {}),
          ...(o.proxy ? { proxy: { server: o.proxy.server, username: o.proxy.username, password: o.proxy.password, bypass: '<-loopback>' } } : {}),
        });
        browser.agInfo = { via: a.via, sandbox, warnings: sandbox ? [] : ['Chrome запущен без песочницы: в этой системе она недоступна. Захват идёт под защитой проверки адресов, но лучше включить песочницу (пользовательские пространства имён) или запускать Chrome в контейнере.'] };
        return browser;
      } catch (e) {
        lastError = e;
      }
    }
    if (lastError) errors.push(lastError);
  }
  const shown = attempts[0] && attempts[0].via === 'path' ? errors[0] : errors[errors.length - 1];
  const msg = String(shown && shown.message || shown || '').split('\n').find((l) => l.trim() !== '' && !/^browserType\.launch/.test(l.trim())) || 'неизвестная причина';
  const err = new Error('Chrome не запустился: ' + msg.trim().slice(0, 300));
  err.code = 'no_chrome';
  throw err;
}

export function decodeHtml(buf, contentType = '') {
  let cs = (/charset=([\w-]+)/i.exec(contentType) || [])[1] || '';
  if (!cs) {
    const head = buf.subarray(0, 4096).toString('latin1');
    cs = (/<meta[^>]+charset=["']?([\w-]+)/i.exec(head) || [])[1] || '';
  }
  try { return new TextDecoder(cs || 'utf-8').decode(buf); } catch { return buf.toString('utf8'); }
}

/**
 * A browser context for one viewport. Counters are refused, files already on disk (cache) are served from there, everything
 * the page loads is saved to the store.
 */
export async function makeContext(browser, kind, { store, log, state, userAgent, extraHeaders }) {
  const vp = VIEWPORTS[kind];
  const ctx = await browser.newContext({
    viewport: { width: vp.width, height: vp.height }, deviceScaleFactor: vp.deviceScaleFactor, isMobile: vp.isMobile, hasTouch: vp.hasTouch,
    userAgent, locale: 'ru-RU', ignoreHTTPSErrors: false, serviceWorkers: 'block', extraHTTPHeaders: extraHeaders || {}, acceptDownloads: false,
  });
  // A window that a page opens for itself is closed at once: the capture works with its own pages only.
  ctx.on('page', (p) => { p.opener().then((o) => { if (o) p.close().catch(() => {}); }).catch(() => {}); });
  const pending = new Set();
  ctx.agPending = pending;
  const KEEP = new Set(['stylesheet', 'script', 'font', 'image', 'media', 'other', 'texttrack']);
  await ctx.route('**/*', async (route) => {
    const req = route.request();
    const url = req.url();
    if (!/^https?:/i.test(url)) {
      // data:, blob: and about: never leave the browser; every other scheme (file:, ftp:, chrome:, ws:) is refused.
      return /^(data|blob|about):/i.test(url) ? route.continue() : route.abort('blockedbyclient');
    }
    // Second line behind the proxy: an address of the internal network is not even tried; a chain of redirects is cut.
    const refused = urlProblem(url, guardState());
    if (refused) {
      state.refused = state.refused || [];
      if (state.refused.length < 100) state.refused.push({ url: url.slice(0, 200), why: refused });
      return route.abort('blockedbyclient');
    }
    let hops = 0;
    for (let r = req.redirectedFrom(); r; r = r.redirectedFrom()) hops++;
    if (hops > 10) return route.abort('blockedbyclient');
    if (isTrackerUrl(url)) {
      state.blocked.add(url.split('?')[0]);
      return route.abort('blockedbyclient');
    }
    const type = req.resourceType();
    if (req.method() === 'GET' && KEEP.has(type) && type !== 'media' && !req.headers().range) {
      const hit = store.cacheGet(url);
      if (hit && hit.status === 200) {
        return route.fulfill({ status: 200, body: hit.body, headers: { 'content-type': hit.contentType || 'application/octet-stream', 'access-control-allow-origin': '*' } });
      }
    }
    return route.continue();
  });
  ctx.on('response', (resp) => {
    const p = (async () => {
      try {
        const req = resp.request();
        const type = req.resourceType();
        const url = resp.url();
        if (req.frame().parentFrame() !== null) return; // files of embedded frames (video players, maps) are not ours
        if (req.method() !== 'GET' || !/^https?:/i.test(url) || isTrackerUrl(url)) return;
        const status = resp.status();
        if (type === 'media' && (status === 200 || status === 206)) { state.media.add(url); return; }
        if (!KEEP.has(type) || status !== 200 || store.has(url)) return;
        const headers = resp.headers();
        const ct = headers['content-type'] || '';
        if (/^text\/html/i.test(ct)) return;
        const body = await resp.body();
        const chain = [];
        for (let r = req.redirectedFrom(); r; r = r.redirectedFrom()) chain.push(r.url());
        store.cachePut(url, status, headers, body);
        store.put(url, body, ct, chain);
      } catch { /* a response that vanished (navigation) is not a file of the page */ }
    })();
    pending.add(p);
    p.finally(() => pending.delete(p));
  });
  return ctx;
}

/** Waits until the files of the last page are saved. */
export const idle = (ctx) => Promise.allSettled([...ctx.agPending]);

/** Scrolls through the page so that lazy pictures and animations fire, then waits for fonts and pictures. */
export async function settle(page) {
  await page.evaluate(async () => {
    const sleepIn = (ms) => new Promise((r) => setTimeout(r, ms));
    const total = () => Math.max(document.body ? document.body.scrollHeight : 0, document.documentElement.scrollHeight);
    const step = Math.max(300, Math.floor(window.innerHeight * 0.7));
    let y = 0;
    for (let i = 0; i < 400 && y < total(); i++) {
      window.scrollTo(0, y);
      y += step;
      await sleepIn(160);
    }
    window.scrollTo(0, total());
    await sleepIn(500);
    const waiting = Array.from(document.images).filter((im) => !im.complete);
    await Promise.race([Promise.all(waiting.map((im) => new Promise((res) => { im.addEventListener('load', res); im.addEventListener('error', res); }))), sleepIn(5000)]);
    if (document.fonts && document.fonts.ready) await Promise.race([document.fonts.ready, sleepIn(3000)]);
    window.scrollTo(0, 0);
    window.dispatchEvent(new Event('scroll'));
    await sleepIn(1000);
    window.dispatchEvent(new Event('scroll'));
    await sleepIn(400);
  });
  await page.waitForLoadState('networkidle', { timeout: 6000 }).catch(() => {});
  // Widgets that build themselves after the scroll (maps, sliders) keep changing the page for a while on a busy machine:
  // wait until the document has been quiet for a moment, but never longer than a few seconds (an animated page never is).
  await page.evaluate(() => new Promise((resolve) => {
    let timer = setTimeout(done, 900);
    const stop = setTimeout(done, 4000);
    const obs = new MutationObserver(() => { clearTimeout(timer); timer = setTimeout(done, 900); });
    obs.observe(document.documentElement, { subtree: true, childList: true, attributes: true, characterData: true });
    function done() { clearTimeout(timer); clearTimeout(stop); obs.disconnect(); resolve(); }
  })).catch(() => {});
}

/**
 * Opens a page. Returns the main document (as served), its final address, the headers and the page object.
 * @returns {Promise<{page, status:number, raw:string, finalUrl:string, headers:object}>}
 */
export async function openPage(ctx, url, { timeout = 45000 } = {}) {
  let page = await ctx.newPage();
  page.on('dialog', (d) => d.dismiss().catch(() => {}));
  let resp;
  try {
    resp = await page.goto(url, { waitUntil: 'load', timeout });
  } catch (e) {
    // A slow or overloaded site: one more try after a pause (a refusal by the address check is final, see netguard).
    if (!/timeout|ERR_TIMED_OUT|ERR_CONNECTION_(RESET|CLOSED|TIMED_OUT)|ERR_EMPTY_RESPONSE/i.test(String(e && e.message))) throw e;
    await page.close().catch(() => {});
    await new Promise((r) => setTimeout(r, 4000));
    page = await ctx.newPage();
    page.on('dialog', (d) => d.dismiss().catch(() => {}));
    resp = await page.goto(url, { waitUntil: 'load', timeout });
  }
  await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
  let raw = '';
  let headers = {};
  let status = 0;
  if (resp) {
    status = resp.status();
    headers = resp.headers();
    try { raw = decodeHtml(await resp.body(), headers['content-type'] || ''); } catch { raw = ''; }
  }
  return { page, status, raw, finalUrl: page.url(), headers };
}

export async function shot(page) {
  const h = await page.evaluate(() => Math.max(document.body ? document.body.scrollHeight : 0, document.documentElement.scrollHeight));
  const vp = page.viewportSize();
  return page.screenshot({ type: 'png', fullPage: true, clip: { x: 0, y: 0, width: vp.width, height: Math.max(200, Math.min(h, 15000)) }, animations: 'disabled', caret: 'hide', scale: 'css' });
}

/** Share of equal pixels of two screenshots (the larger height counts, so a page of another height scores lower). */
export async function compareShots(page, a, b) {
  return page.evaluate(async ([x, y]) => {
    const load = (d) => new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = rej; i.src = 'data:image/png;base64,' + d; });
    const [A, B] = await Promise.all([load(x), load(y)]);
    const w = Math.min(A.width, B.width);
    const h = Math.min(A.height, B.height, 15000);
    const grab = (img) => { const c = document.createElement('canvas'); c.width = w; c.height = h; const g = c.getContext('2d', { willReadFrequently: true }); g.drawImage(img, 0, 0); return g.getImageData(0, 0, w, h).data; };
    const da = grab(A);
    const db = grab(B);
    let equal = 0;
    const bands = 20;
    const bandEq = new Array(bands).fill(0);
    const bandN = new Array(bands).fill(0);
    for (let row = 0; row < h; row++) {
      const band = Math.min(bands - 1, Math.floor((row / h) * bands));
      let eq = 0;
      for (let col = 0; col < w; col++) {
        const i = (row * w + col) * 4;
        if (Math.abs(da[i] - db[i]) + Math.abs(da[i + 1] - db[i + 1]) + Math.abs(da[i + 2] - db[i + 2]) <= 48) eq++;
      }
      equal += eq;
      bandEq[band] += eq;
      bandN[band] += w;
    }
    const tall = Math.max(A.height, B.height);
    return {
      similarity: equal / (w * tall), sameRegion: equal / (w * h), heightA: A.height, heightB: B.height, width: w,
      bands: bandEq.map((e, i) => (bandN[i] ? Math.round((e / bandN[i]) * 100) / 100 : 1)),
    };
  }, [a.toString('base64'), b.toString('base64')]);
}

/** A file in full through Node (videos come in ranges through the browser; CSS references that nothing loaded). Same address checks as the proxy. */
export function fetchFull(url, { maxBytes, userAgent, referer, timeout = 40000 }) {
  return safeGet(url, { maxBytes, userAgent, referer, timeout });
}

export const mobileUserAgent = (base) => base.replace(/\(([^)]*)\)/, '(Linux; Android 13; Pixel 7)').replace(/Chrome\/([\d.]+) Safari/, 'Chrome/$1 Mobile Safari');
export const makeUserAgent = (version) => `Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/${version} Safari/537.36 ${UA_SUFFIX}`;
export { sleep };
