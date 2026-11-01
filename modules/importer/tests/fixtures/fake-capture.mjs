// Stand-in for tools/capture/capture.mjs in the PHP tests: the same arguments, the same status file, no browser.
// The address picks the behaviour: "slow" runs until it is stopped, "boom" fails, anything else finishes quickly.
import fs from 'node:fs';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const argv = process.argv.slice(2);
const opt = {};
let url = '';
for (const a of argv) {
  const m = /^--([^=]+)(?:=(.*))?$/.exec(a);
  if (m) opt[m[1]] = m[2] === undefined ? true : m[2];
  else if (!url) url = a;
}
if (opt['self-test']) { console.log('ok'); process.exit(0); }
if (opt.detach) {
  const args = argv.filter((a) => a !== '--detach');
  const log = fs.openSync(opt.log || '/dev/null', 'a');
  const child = spawn(process.execPath, [fileURLToPath(import.meta.url), ...args], { detached: true, stdio: ['ignore', log, log] });
  child.unref();
  console.log(JSON.stringify({ pid: child.pid }));
  process.exit(0);
}

const state = { state: 'running', phase: 'start', message: 'start', pid: process.pid, token: opt.token || '', started: Date.now(), updated: Date.now(), pages: 0, files: 0, bytes: 0, warnings: [], blocked: 0, limit: Number(opt.limit || 50) };
const write = (patch = {}) => {
  Object.assign(state, patch, { updated: Date.now() });
  fs.mkdirSync(path.dirname(opt.status), { recursive: true });
  fs.writeFileSync(opt.status + '.tmp', JSON.stringify(state));
  fs.renameSync(opt.status + '.tmp', opt.status);
};
write();
const beat = setInterval(() => write(), 300);
process.on('SIGTERM', () => { clearInterval(beat); write({ state: 'cancelled', message: 'stopped' }); process.exit(143); });

const wait = (ms) => new Promise((r) => setTimeout(r, ms));
if (url.includes('slow')) {
  for (let i = 0; i < 400; i++) { write({ phase: 'crawl', pages: i % 5, url: url }); await wait(250); }
}
if (url.includes('boom')) {
  clearInterval(beat);
  write({ state: 'failed', code: 'error', error: 'Сайт не открылся: тестовый отказ', message: 'fail' });
  process.exit(1);
}
if (opt.scout) {
  clearInterval(beat);
  write({ state: 'done', phase: 'done', message: 'scouted', result: { platform: 'tilda', title: 'Тестовый сайт', pages_found: 2, pages_planned: 2, home_files: 3, home_bytes: 3000, estimate_bytes: 20000, estimate_seconds: 20, status: 200, final_url: url } });
  process.exit(0);
}
// A capture: a package folder with two pages.
const out = opt.out;
const put = (name, bytes) => { fs.mkdirSync(path.dirname(path.join(out, name)), { recursive: true }); fs.writeFileSync(path.join(out, name), bytes); };
const S = '/__agecms_copy__/';
const page = (t) => `<html lang="ru"><head><meta charset="utf-8"><link rel="stylesheet" href="${S}old.test/css/a.css"></head><body><h1>${t}</h1><p>Текст страницы ${t}</p></body></html>`;
put('manifest.json', JSON.stringify({
  format: 'agecms-capture/1', tool: { name: 'agecms-capture', version: '1.0.0' }, source: { url, kind: 'url' }, platform: 'tilda', site: { title: 'Тестовый сайт' },
  totals: { files: 1, bytes: 40 }, trackers_removed: [],
  pages: [
    { id: 1, url: 'https://old.test/', path: '/', file: 'pages/0001.html', mode: 'source', platform: 'tilda', title: 'Главная', description: '', noindex: false, forms: [], trackers: [], notes: [], verify: { desktop: 0.99, mobile: 0.98 } },
    { id: 2, url: 'https://old.test/about', path: '/about', file: 'pages/0002.html', mode: 'source', platform: 'tilda', title: 'О нас', description: '', noindex: false, forms: [], trackers: [], notes: [], verify: { desktop: 0.99, mobile: 0.98 } },
  ],
}));
put('pages/0001.html', page('Главная'));
put('pages/0002.html', page('О нас'));
put('files/old.test/css/a.css', 'body{margin:0}');
clearInterval(beat);
write({ state: 'done', phase: 'done', message: 'ready', pages: 2, files: 1, bytes: 40, out });
