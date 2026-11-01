// Chrome: start, open a page the way a visitor does (scroll, wait for pictures and fonts), collect the files it loads.
import fs from 'node:fs';
import { chromium } from 'playwright-core';
import { isTrackerUrl } from './trackers.mjs';
import { UA_SUFFIX, sleep } from './util.mjs';

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

/** Path of an installed Chrome/Chromium, or null. */
export function findChrome(preferred) {
  for (const p of [preferred, process.env.CHROME_PATH, ...CHROME_PATHS]) {
    if (p && fs.existsSync(p)) return p;
  }
  return null;
}

export async function launch(chromePath, headless = true) {
  return chromium.launch({ executablePath: chromePath, headless, args: ['--disable-blink-features=AutomationControlled', '--hide-scrollbars', '--force-color-profile=srgb', '--font-render-hinting=none', '--disable-dev-shm-usage'] });
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
    userAgent, locale: 'ru-RU', ignoreHTTPSErrors: false, serviceWorkers: 'block', extraHTTPHeaders: extraHeaders || {},
  });
  const pending = new Set();
  ctx.agPending = pending;
  const KEEP = new Set(['stylesheet', 'script', 'font', 'image', 'media', 'other', 'texttrack']);
  await ctx.route('**/*', async (route) => {
    const req = route.request();
    const url = req.url();
    if (!/^https?:/i.test(url)) return route.continue();
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
}

/**
 * Opens a page. Returns the main document (as served), its final address, the headers and the page object.
 * @returns {Promise<{page, status:number, raw:string, finalUrl:string, headers:object}>}
 */
export async function openPage(ctx, url, { timeout = 45000 } = {}) {
  const page = await ctx.newPage();
  page.on('dialog', (d) => d.dismiss().catch(() => {}));
  const resp = await page.goto(url, { waitUntil: 'load', timeout });
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

/** A file in full through Node (videos come in ranges through the browser; CSS references that nothing loaded). */
export async function fetchFull(url, { maxBytes, userAgent, referer, timeout = 40000 }) {
  const ctl = new AbortController();
  const timer = setTimeout(() => ctl.abort(), timeout);
  try {
    const r = await fetch(url, { signal: ctl.signal, redirect: 'follow', headers: { 'user-agent': userAgent, referer: referer || '', accept: '*/*', 'accept-language': 'ru-RU,ru;q=0.9' } });
    if (!r.ok) return { ok: false, status: r.status };
    const len = Number(r.headers.get('content-length') || 0);
    if (len > maxBytes) return { ok: false, status: 413, why: 'слишком большой файл' };
    const chunks = [];
    let size = 0;
    for await (const c of r.body) {
      size += c.length;
      if (size > maxBytes) { ctl.abort(); return { ok: false, status: 413, why: 'слишком большой файл' }; }
      chunks.push(c);
    }
    return { ok: true, status: r.status, body: Buffer.concat(chunks), contentType: r.headers.get('content-type') || '', finalUrl: r.url };
  } catch (e) {
    return { ok: false, status: 0, why: String(e && e.message || e).slice(0, 100) };
  } finally {
    clearTimeout(timer);
  }
}

export const mobileUserAgent = (base) => base.replace(/\(([^)]*)\)/, '(Linux; Android 13; Pixel 7)').replace(/Chrome\/([\d.]+) Safari/, 'Chrome/$1 Mobile Safari');
export const makeUserAgent = (version) => `Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/${version} Safari/537.36 ${UA_SUFFIX}`;
export { sleep };
