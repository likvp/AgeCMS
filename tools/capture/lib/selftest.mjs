// `node capture.mjs --self-test`: starts the browser the way a capture does (guard proxy, sandbox on), opens about:blank, checks
// that the guard really stops a request to this machine, prints "ok" and returns 0. Otherwise the reason goes to stderr and 1 is returned.
import http from 'node:http';
import { findChrome, launch } from './browser.mjs';
import { startGuardProxy, configureGuard } from './netguard.mjs';

export async function selfTest({ chrome = null, sandbox = 'auto' } = {}) {
  let proxy = null;
  let browser = null;
  let trap = null;
  try {
    const major = Number(String(process.versions.node).split('.')[0]);
    if (major < 20) throw new Error(`нужен Node.js 20 или новее, стоит ${process.versions.node}`);
    configureGuard({ allowPrivate: false });
    proxy = await startGuardProxy({});
    const chromePath = findChrome(typeof chrome === 'string' ? chrome : null);
    browser = await launch({
      chromePath, explicit: typeof chrome === 'string' || !!process.env.AGECMS_CHROME, headless: true, sandbox,
      proxy: { server: proxy.server, username: proxy.user, password: proxy.pass },
    });
    const ctx = await browser.newContext({ acceptDownloads: false });
    const page = await ctx.newPage();
    await page.goto('about:blank');
    // The guard: a server on this machine that must not be reached.
    let hits = 0;
    trap = http.createServer((req, res) => { hits++; res.end('reached'); });
    await new Promise((r) => trap.listen(0, '127.0.0.1', r));
    const url = `http://127.0.0.1:${trap.address().port}/`;
    let blocked = false;
    try {
      const resp = await page.goto(url, { timeout: 10000 });
      blocked = !resp || resp.status() === 403 || resp.status() === 407;
    } catch { blocked = true; }
    if (!blocked || hits > 0) throw new Error('защита адресов не остановила запрос к этому компьютеру: захват небезопасен, запускать его нельзя');
    const info = browser.agInfo;
    process.stderr.write(`Chrome ${browser.version()}, запуск: ${info.via}, песочница: ${info.sandbox ? 'включена' : 'выключена'}\n`);
    for (const w of info.warnings) process.stderr.write('Предупреждение: ' + w + '\n');
    process.stdout.write('ok\n');
    return 0;
  } catch (e) {
    process.stderr.write('Ошибка: ' + String((e && e.message) || e).split('\n')[0].slice(0, 400) + '\n');
    return 1;
  } finally {
    if (trap) trap.close();
    if (browser) await Promise.race([browser.close().catch(() => {}), new Promise((r) => setTimeout(r, 4000))]);
    if (proxy) proxy.close();
  }
}
