// The set of files saved from the site: address -> place in the package. Also the disk cache of responses
// (a second run on the same site does not ask the site again) and the limits.
import fs from 'node:fs';
import path from 'node:path';
import { ALLOWED_EXT, extOf, localName, sha1, sha256 } from './util.mjs';

export class Store {
  /**
   * @param {string} dir folder of the files (files/ of the package under construction)
   * @param {object} o {cacheDir, maxFileBytes, maxTotalBytes}
   */
  constructor(dir, o) {
    this.dir = dir;
    this.cacheDir = o.cacheDir;
    this.maxFile = o.maxFileBytes;
    this.maxTotal = o.maxTotalBytes;
    this.map = {}; // absolute address (no fragment) -> "host/path"
    this.info = new Map(); // local -> {url, type, bytes, sha256}
    this.taken = new Map(); // local -> url
    this.failed = new Set();
    this.skipped = []; // {url, why}
    this.total = 0;
    fs.mkdirSync(dir, { recursive: true });
    if (this.cacheDir) fs.mkdirSync(this.cacheDir, { recursive: true });
  }

  has(url) { return Object.prototype.hasOwnProperty.call(this.map, url); }

  /** Saves a body under the address (and the addresses it was redirected from). Returns the local name or null. */
  put(url, body, contentType, aliases = []) {
    if (this.has(url)) return this.map[url];
    const ct = (contentType || '').split(';')[0].trim().toLowerCase();
    const ext = extOf(new URL(url).pathname);
    if (body.length > this.maxFile) { this.skipped.push({ url, why: 'файл больше ' + Math.round(this.maxFile / 1048576) + ' МБ' }); this.failed.add(url); return null; }
    if (this.total + body.length > this.maxTotal) { this.skipped.push({ url, why: 'достигнут общий предел размера' }); this.failed.add(url); return null; }
    if (ext && !ALLOWED_EXT.has(ext)) { this.skipped.push({ url, why: 'тип файла .' + ext + ' не переносится' }); this.failed.add(url); return null; }
    if (/^text\/html/.test(ct)) { this.skipped.push({ url, why: 'это страница, а не файл' }); this.failed.add(url); return null; }
    const local = localName(url, ct, this.taken);
    const file = path.join(this.dir, ...local.split('/'));
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, body);
    this.taken.set(local, url);
    this.map[url] = local;
    for (const a of aliases) this.map[a] = local;
    this.info.set(local, { url, type: ct, bytes: body.length, sha256: sha256(body) });
    this.total += body.length;
    return local;
  }

  /** Text of a saved file (CSS, JS). */
  read(local) { return fs.readFileSync(path.join(this.dir, ...local.split('/'))); }
  write(local, data) { fs.writeFileSync(path.join(this.dir, ...local.split('/')), data); const i = this.info.get(local); if (i) { const b = Buffer.from(data); this.total += b.length - i.bytes; i.bytes = b.length; i.sha256 = sha256(b); } }

  // ---- disk cache of responses ----------------------------------------------------------------------------------
  cacheGet(url) {
    if (!this.cacheDir) return null;
    const k = path.join(this.cacheDir, sha1(url));
    try {
      const meta = JSON.parse(fs.readFileSync(k + '.json', 'utf8'));
      return { ...meta, body: fs.readFileSync(k + '.bin') };
    } catch { return null; }
  }
  cachePut(url, status, headers, body) {
    if (!this.cacheDir || body.length > this.maxFile) return;
    const k = path.join(this.cacheDir, sha1(url));
    try {
      fs.writeFileSync(k + '.bin', body);
      fs.writeFileSync(k + '.json', JSON.stringify({ url, status, contentType: headers['content-type'] || '' }));
    } catch { /* a full disk only costs the cache */ }
  }
}
