// Two tiny local servers: one shows a saved site folder to Chrome (so a Tilda export or a saved folder is captured like a live site),
// the other shows a finished package the way the importer will (for the self-check).
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { SENTINEL } from './util.mjs';

const TYPES = {
  html: 'text/html; charset=utf-8', htm: 'text/html; charset=utf-8', css: 'text/css; charset=utf-8', js: 'text/javascript; charset=utf-8', mjs: 'text/javascript; charset=utf-8',
  json: 'application/json', svg: 'image/svg+xml', png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', webp: 'image/webp', avif: 'image/avif',
  ico: 'image/x-icon', woff: 'font/woff', woff2: 'font/woff2', ttf: 'font/ttf', otf: 'font/otf', eot: 'application/vnd.ms-fontobject',
  mp4: 'video/mp4', webm: 'video/webm', mp3: 'audio/mpeg', m4a: 'audio/mp4', ogg: 'audio/ogg', pdf: 'application/pdf', txt: 'text/plain; charset=utf-8', vtt: 'text/vtt', xml: 'application/xml', map: 'application/json',
};
const typeOf = (f) => TYPES[path.extname(f).slice(1).toLowerCase()] || 'application/octet-stream';

function listen(server) {
  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server.address().port)));
}

function sendFile(req, res, file) {
  const st = fs.statSync(file);
  const range = /bytes=(\d*)-(\d*)/.exec(req.headers.range || '');
  const headers = { 'Content-Type': typeOf(file), 'Accept-Ranges': 'bytes', 'Cache-Control': 'no-store' };
  if (range && (range[1] || range[2])) {
    const start = range[1] ? parseInt(range[1], 10) : Math.max(0, st.size - parseInt(range[2], 10));
    const end = range[1] && range[2] ? Math.min(parseInt(range[2], 10), st.size - 1) : st.size - 1;
    res.writeHead(206, { ...headers, 'Content-Range': `bytes ${start}-${end}/${st.size}`, 'Content-Length': end - start + 1 });
    fs.createReadStream(file, { start, end }).pipe(res);
    return;
  }
  res.writeHead(200, { ...headers, 'Content-Length': st.size });
  if (req.method === 'HEAD') { res.end(); return; }
  fs.createReadStream(file).pipe(res);
}

/** A saved site (folder) at http://127.0.0.1:<port>/ : "/" is index.html, "/about" is about.html when there is no folder. */
export async function serveFolder(root) {
  root = path.resolve(root);
  const server = http.createServer((req, res) => {
    let p;
    try { p = decodeURIComponent(new URL(req.url, 'http://x').pathname); } catch { res.writeHead(400).end(); return; }
    let file = path.join(root, p);
    if (!file.startsWith(root)) { res.writeHead(403).end(); return; }
    const tryFiles = [file, path.join(file, 'index.html'), file + '.html'];
    for (const f of tryFiles) {
      try { if (fs.statSync(f).isFile()) { sendFile(req, res, f); return; } } catch { /* next */ }
    }
    res.writeHead(404, { 'Content-Type': 'text/plain' }).end('not found');
  });
  const port = await listen(server);
  return { url: `http://127.0.0.1:${port}`, close: () => server.close() };
}

/** A finished package as a site: every page at its old path, files under /__agecms_copy__/. No security headers on purpose. */
export async function servePackage(pkgDir, pages, extraHeaders = {}) {
  const byPath = new Map();
  for (const p of pages) byPath.set(p.pathKey, path.join(pkgDir, p.file));
  const filesRoot = path.join(pkgDir, 'files');
  const requests = [];
  const server = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    let p;
    try { p = decodeURIComponent(u.pathname); } catch { res.writeHead(400).end(); return; }
    requests.push(req.url);
    if (p.startsWith(SENTINEL)) {
      const f = path.join(filesRoot, p.slice(SENTINEL.length));
      if (f.startsWith(filesRoot) && fs.existsSync(f) && fs.statSync(f).isFile()) { sendFile(req, res, f); return; }
      res.writeHead(404, { 'Content-Type': 'text/plain' }).end('no such file');
      return;
    }
    const key = (p.length > 1 && p.endsWith('/') ? p.slice(0, -1) : p) + u.search;
    const f = byPath.get(key) || byPath.get(p);
    if (f) {
      const body = fs.readFileSync(f);
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store', ...extraHeaders });
      res.end(req.method === 'HEAD' ? undefined : body);
      return;
    }
    res.writeHead(404, { 'Content-Type': 'text/plain' }).end('not found');
  });
  const port = await listen(server);
  return { url: `http://127.0.0.1:${port}`, requests, close: () => server.close() };
}
