// Small helpers shared by the capture tool: logging, file names, robots.txt, limits.
import { createHash } from 'node:crypto';
import path from 'node:path';

export const FORMAT = 'agecms-capture/1';
export const TOOL_VERSION = '1.0.0';
/** Prefix of every address of a saved file inside a captured page; the importer replaces it with the real folder. */
export const SENTINEL = '/__agecms_copy__/';
export const UA_SUFFIX = 'AgeCMS-capture/' + TOOL_VERSION;

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
export const sha1 = (s) => createHash('sha1').update(s).digest('hex');
export const sha256 = (b) => createHash('sha256').update(b).digest('hex');

export class Log {
  constructor(file, verbose = true) {
    this.lines = [];
    this.verbose = verbose;
    this.file = file;
  }
  add(level, msg) {
    const line = `${new Date().toISOString().slice(11, 19)} ${level} ${msg}`;
    this.lines.push(line);
    if (this.verbose || level !== 'info') process.stderr.write(line + '\n');
  }
  info(m) { this.add('info', m); }
  warn(m) { this.add('WARN', m); }
  error(m) { this.add('ERROR', m); }
  text() { return this.lines.join('\n') + '\n'; }
}

/** Type of a file by content type or extension: what the importer is allowed to keep. */
export const MIME_EXT = {
  'text/css': 'css', 'text/javascript': 'js', 'application/javascript': 'js', 'application/x-javascript': 'js', 'text/ecmascript': 'js',
  'application/json': 'json', 'image/svg+xml': 'svg', 'image/png': 'png', 'image/jpeg': 'jpg', 'image/jpg': 'jpg', 'image/gif': 'gif',
  'image/webp': 'webp', 'image/avif': 'avif', 'image/x-icon': 'ico', 'image/vnd.microsoft.icon': 'ico', 'image/bmp': 'bmp',
  'font/woff': 'woff', 'font/woff2': 'woff2', 'font/ttf': 'ttf', 'font/otf': 'otf', 'application/font-woff': 'woff', 'application/font-woff2': 'woff2',
  'application/x-font-ttf': 'ttf', 'application/x-font-woff': 'woff', 'application/vnd.ms-fontobject': 'eot', 'font/sfnt': 'ttf',
  'video/mp4': 'mp4', 'video/webm': 'webm', 'audio/mpeg': 'mp3', 'audio/mp4': 'm4a', 'audio/ogg': 'ogg', 'video/ogg': 'ogv', 'audio/wav': 'wav',
  'application/pdf': 'pdf', 'text/plain': 'txt', 'text/vtt': 'vtt', 'application/zip': 'zip',
};
export const ALLOWED_EXT = new Set(['css', 'js', 'mjs', 'json', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'bmp', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm', 'mp3', 'm4a', 'ogg', 'ogv', 'wav', 'pdf', 'txt', 'vtt', 'docx', 'doc', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'rtf', 'zip', 'map', 'cur', 'wasm']);

export function extOf(urlPath) {
  const m = /\.([A-Za-z0-9]{1,6})$/.exec(urlPath.split('?')[0]);
  return m ? m[1].toLowerCase() : '';
}

/**
 * Where a file lives in the package: files/<host>/<path>. Only [A-Za-z0-9._~-] and "/" are kept, the rest becomes "_",
 * so the address needs no escaping anywhere. A file with a different query than an earlier one at the same path gets a
 * short hash before the extension.
 */
export function localName(url, contentType, taken) {
  const u = new URL(url);
  let p = decodeSafe(u.pathname);
  if (p.endsWith('/')) p += 'index';
  let ext = extOf(p);
  const ct = (contentType || '').split(';')[0].trim().toLowerCase();
  if (!ext || !ALLOWED_EXT.has(ext)) {
    const byType = MIME_EXT[ct];
    if (byType) p += '.' + byType;
    else if (!ext) p += '.bin';
  }
  // A name that looks like a script of a server (file.php) would be refused by the importer: the dot becomes an underscore.
  const safe = p.split('/').map((seg) => seg.replace(/[^A-Za-z0-9._~-]+/g, '_').replace(/^\.+/, '_').replace(/\.(php[0-9]?|phtml|phar|pht|inc|cgi|pl|py|sh|htaccess)(?=\.|$)/gi, '_$1').slice(0, 90)).filter((s) => s !== '').join('/');
  const host = u.host.replace(/[^A-Za-z0-9.-]+/g, '_');
  let rel = host + '/' + (safe || 'file');
  if (taken && taken.has(rel) && taken.get(rel) !== url) {
    const dot = rel.lastIndexOf('.');
    const tag = '__' + sha1(url).slice(0, 8);
    rel = dot > rel.lastIndexOf('/') ? rel.slice(0, dot) + tag + rel.slice(dot) : rel + tag;
  }
  if (rel.length > 200) {
    const dot = rel.lastIndexOf('.');
    rel = rel.slice(0, 150) + '__' + sha1(url).slice(0, 8) + (dot > 0 ? rel.slice(dot) : '');
  }
  return rel;
}

function decodeSafe(s) {
  try { return decodeURIComponent(s); } catch { return s; }
}

/** www.example.com and example.com are one site. */
export const bareHost = (h) => h.toLowerCase().replace(/^www\./, '');

export function sameSite(a, b) {
  try { return bareHost(new URL(a).host) === bareHost(new URL(b).host); } catch { return false; }
}

/** Query parameters that only track visitors: dropped when comparing addresses. */
const TRACK_PARAM = /^(utm_[a-z]+|yclid|gclid|fbclid|_openstat|ysclid|from|ref|openstat|_ga|_gl|mc_cid|mc_eid|roistat_visit)$/i;

/** Address of a page as the crawler compares it: no fragment, no tracking parameters, no trailing "index". */
export function pageKey(url) {
  const u = new URL(url);
  u.hash = '';
  for (const k of [...u.searchParams.keys()]) if (TRACK_PARAM.test(k)) u.searchParams.delete(k);
  u.searchParams.sort();
  let p = u.pathname.replace(/\/index\.(html?|php)$/i, '/');
  if (p.length > 1 && p.endsWith('/')) p = p.slice(0, -1);
  return bareHost(u.host) + p + (u.search || '');
}

/** The path and query of a page as the old site knew it ("/about?x=1"); the importer turns it into the 301 source. */
export function oldPath(url) {
  const u = new URL(url);
  for (const k of [...u.searchParams.keys()]) if (TRACK_PARAM.test(k)) u.searchParams.delete(k);
  return (u.pathname || '/') + (u.search || '');
}

/** robots.txt: the longest matching rule of the group for our agent (or *) wins, Allow before Disallow on a tie. */
export function parseRobots(text) {
  const groups = [];
  let cur = null;
  let lastWasAgent = false;
  const sitemaps = [];
  for (const raw of text.split(/\r?\n/)) {
    const line = raw.replace(/#.*$/, '').trim();
    const m = /^([A-Za-z-]+)\s*:\s*(.*)$/.exec(line);
    if (!m) continue;
    const k = m[1].toLowerCase();
    const v = m[2].trim();
    if (k === 'user-agent') {
      if (!lastWasAgent || !cur) { cur = { agents: [], rules: [] }; groups.push(cur); }
      cur.agents.push(v.toLowerCase());
      lastWasAgent = true;
      continue;
    }
    lastWasAgent = false;
    if (k === 'sitemap') sitemaps.push(v);
    else if (cur && (k === 'allow' || k === 'disallow') && v !== '') cur.rules.push({ allow: k === 'allow', pattern: v });
    else if (cur && k === 'disallow') cur.rules.push({ allow: true, pattern: '/' });
  }
  const pick = (name) => groups.filter((g) => g.agents.some((a) => a !== '*' && name.includes(a)));
  let use = pick('agecms-capture');
  if (use.length === 0) use = groups.filter((g) => g.agents.includes('*'));
  const rules = use.flatMap((g) => g.rules);
  const toRe = (p) => new RegExp('^' + p.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*').replace(/\\\$$/, '$'));
  return {
    sitemaps,
    allowed(pathAndQuery) {
      let best = null;
      for (const r of rules) {
        if (toRe(r.pattern).test(pathAndQuery)) {
          if (!best || r.pattern.length > best.pattern.length || (r.pattern.length === best.pattern.length && r.allow)) best = r;
        }
      }
      return best === null || best.allow;
    },
  };
}

export function humanBytes(n) {
  if (n < 1024) return n + ' Б';
  if (n < 1048576) return (n / 1024).toFixed(1) + ' КБ';
  return (n / 1048576).toFixed(1) + ' МБ';
}

export function parseArgs(argv) {
  const opts = {};
  const positional = [];
  for (const a of argv) {
    if (a.startsWith('--')) {
      const [k, ...rest] = a.slice(2).split('=');
      opts[k] = rest.length ? rest.join('=') : true;
    } else positional.push(a);
  }
  return { opts, positional };
}

export const rel = (from, to) => path.relative(from, to).split(path.sep).join('/');
