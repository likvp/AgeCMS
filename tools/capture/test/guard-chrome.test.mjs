// The guard against a real Chrome: the browser of a capture must not reach this machine or its network.
// Skipped when there is no Chrome on this machine.
import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import fs from 'node:fs';
import { findChrome, launch, makeContext, openPage, fetchFull } from '../lib/browser.mjs';
import { startGuardProxy, configureGuard, allowLocalPort, releaseLocalPort } from '../lib/netguard.mjs';
import { Store } from '../lib/store.mjs';

let chrome = null;
try { chrome = findChrome(null); } catch { chrome = null; }
const listen = (server) => new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server.address().port)));

test('Chrome with the guard cannot open this machine, file: or chrome: addresses', { skip: chrome ? false : 'no Chrome here', timeout: 90000 }, async () => {
  configureGuard({ allowPrivate: false });
  let hits = 0;
  const trap = http.createServer((req, res) => { hits++; res.writeHead(200, { 'content-type': 'text/html' }); res.end('<h1>internal</h1>'); });
  const trapPort = await listen(trap);
  let frontHits = 0;
  const front = http.createServer((req, res) => {
    frontHits++;
    if (req.url === '/redirect') { res.writeHead(302, { Location: `http://127.0.0.1:${trapPort}/` }); res.end(); return; }
    if (req.url === '/fetch') {
      res.writeHead(200, { 'content-type': 'text/html' });
      res.end(`<script>fetch('http://127.0.0.1:${trapPort}/x',{mode:'no-cors'}).catch(()=>{}); var i=new Image(); i.src='http://127.0.0.1:${trapPort}/img.png'; try{new WebSocket('ws://127.0.0.1:${trapPort}/')}catch(e){}</script>front`);
      return;
    }
    res.writeHead(200, { 'content-type': 'text/html' });
    res.end('<h1>front</h1>');
  });
  const frontPort = await listen(front);
  const proxy = await startGuardProxy({});
  proxy.allowLocal(frontPort);
  const browser = await launch({ chromePath: chrome, headless: true, sandbox: 'auto', proxy: { server: proxy.server, username: proxy.user, password: proxy.pass } });
  try {
    const ctx = await browser.newContext({ acceptDownloads: false });
    const page = await ctx.newPage();
    const refused = async (url) => {
      try {
        const r = await page.goto(url, { timeout: 8000 });
        return r === null || r.status() === 403 || r.status() === 407 || (await page.content()).includes('internal') === false;
      } catch { return true; }
    };
    for (const url of [`http://127.0.0.1:${trapPort}/`, `http://localhost:${trapPort}/`, `http://[::1]:${trapPort}/`, `http://0.0.0.0:${trapPort}/`, `http://2130706433:${trapPort}/`,
      'http://169.254.169.254/latest/meta-data/', 'file:///etc/hosts', 'chrome://version', 'ftp://example.com/x']) {
      assert.ok(await refused(url), url + ' must not open');
    }
    assert.equal(hits, 0, 'the internal server must not be touched by a page load');
    // our own server works
    const ok = await page.goto(`http://127.0.0.1:${frontPort}/`);
    assert.equal(ok.status(), 200);
    assert.match(await page.content(), /front/);
    // a redirect from an allowed server to an internal one is cut
    let after;
    try { const r = await page.goto(`http://127.0.0.1:${frontPort}/redirect`, { timeout: 8000 }); after = r && r.status(); } catch { after = 'error'; }
    assert.notEqual(after, 200);
    assert.equal(hits, 0, 'a redirect must not reach the internal server');
    // scripts of a page cannot reach it either (fetch, image, WebSocket)
    await page.goto(`http://127.0.0.1:${frontPort}/fetch`);
    await new Promise((r) => setTimeout(r, 800));
    assert.equal(hits, 0, 'requests made by the page must not reach the internal server');
    assert.ok(proxy.stats.blocked.length > 0);
    assert.equal(browser.agInfo.sandbox || browser.agInfo.warnings.length > 0, true);
  } finally {
    await browser.close();
    proxy.close();
    trap.close();
    front.close();
  }
});

test('the contexts of a capture refuse the internal network and downloads, and fetchFull obeys the same rules', { skip: chrome ? false : 'no Chrome here', timeout: 90000 }, async () => {
  configureGuard({ allowPrivate: false });
  let hits = 0;
  const trap = http.createServer((req, res) => { hits++; res.writeHead(200, { 'content-type': 'text/css' }); res.end('body{}'); });
  const trapPort = await listen(trap);
  const front = http.createServer((req, res) => {
    if (req.url === '/dl') { res.writeHead(200, { 'content-type': 'application/octet-stream', 'content-disposition': 'attachment; filename=a.bin' }); res.end('x'); return; }
    res.writeHead(200, { 'content-type': 'text/html' });
    res.end(`<link rel=stylesheet href="http://127.0.0.1:${trapPort}/a.css"><link rel=stylesheet href="http://localhost:${trapPort}/b.css"><h1>front</h1><a href="/dl">d</a>`);
  });
  const frontPort = await listen(front);
  allowLocalPort(frontPort);
  const proxy = await startGuardProxy({});
  proxy.allowLocal(frontPort);
  const browser = await launch({ chromePath: chrome, headless: true, sandbox: 'auto', proxy: { server: proxy.server, username: proxy.user, password: proxy.pass } });
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'agcap-test-'));
  try {
    const state = { blocked: new Set(), media: new Set(), refused: [] };
    const store = new Store(path.join(dir, 'files'), { cacheDir: null, maxFileBytes: 1e7, maxTotalBytes: 1e8 });
    const ctx = await makeContext(browser, 'desktop', { store, log: { info() {}, warn() {} }, state, userAgent: 'test' });
    const res = await openPage(ctx, `http://127.0.0.1:${frontPort}/`);
    await res.page.waitForTimeout(500);
    assert.equal(res.status, 200);
    assert.equal(hits, 0, 'styles from the internal network must not be loaded');
    assert.ok(state.refused.length >= 2, 'refusals are recorded: ' + JSON.stringify(state.refused));
    assert.equal(Object.keys(store.map).length, 0);
    // a download is not accepted
    const dl = await ctx.newPage();
    let downloaded = false;
    dl.on('download', () => { downloaded = true; });
    await dl.goto(`http://127.0.0.1:${frontPort}/dl`).catch(() => {});
    await dl.waitForTimeout(300);
    assert.equal(fs.readdirSync(dir).filter((n) => n.endsWith('.bin')).length, 0);
    void downloaded;
    // the Node side obeys the same rules
    const r = await fetchFull(`http://127.0.0.1:${trapPort}/a.css`, { maxBytes: 1e6, userAgent: 't' });
    assert.equal(r.ok, false);
    assert.equal(hits, 0);
  } finally {
    await browser.close();
    proxy.close();
    releaseLocalPort(frontPort);
    trap.close();
    front.close();
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
