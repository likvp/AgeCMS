// Network guard of the capture tool.
//
// The browser that captures a site is driven by an administrator of a web site, so it must not become a way into the network
// of the server (SSRF): no localhost, no private ranges, no cloud metadata, no file: or chrome: addresses. Two layers:
//
//  1. Chrome is started with a proxy that lives inside this process (startGuardProxy). Chrome never resolves a host name itself:
//     the proxy resolves it, checks EVERY address of the answer, connects to the address it has checked and to no other
//     (so a name that answers a public address first and 127.0.0.1 second - DNS rebinding - gets nothing), and refuses
//     the rest. Redirects are followed by Chrome, so every hop passes the proxy again.
//  2. Files that Node fetches itself (robots.txt, sitemaps, videos, files named in CSS) go through safeGet, which does the
//     same checks hop by hop and pins the connection to the checked address.
//
// With allowPrivate (command line only, for tests on a machine of your own) both layers let private addresses through.
import net from 'node:net';
import dns from 'node:dns';
import http from 'node:http';
import https from 'node:https';
import zlib from 'node:zlib';
import { randomBytes } from 'node:crypto';

// ---- addresses ---------------------------------------------------------------------------------------------------

/** [network, prefix length] of IPv4 ranges that are not the public internet. */
const V4_BLOCKS = [
  ['0.0.0.0', 8], // "this" network
  ['10.0.0.0', 8], // private
  ['100.64.0.0', 10], // carrier-grade NAT
  ['127.0.0.0', 8], // loopback
  ['169.254.0.0', 16], // link-local, cloud metadata
  ['172.16.0.0', 12], // private
  ['192.0.0.0', 24], // IETF protocol assignments
  ['192.0.2.0', 24], // documentation
  ['192.88.99.0', 24], // 6to4 relay
  ['192.168.0.0', 16], // private
  ['198.18.0.0', 15], // benchmarking
  ['198.51.100.0', 24], // documentation
  ['203.0.113.0', 24], // documentation
  ['224.0.0.0', 4], // multicast
  ['240.0.0.0', 4], // reserved, broadcast
];

function v4ToInt(ip) {
  const p = ip.split('.').map((x) => parseInt(x, 10));
  return ((p[0] * 16777216) + (p[1] * 65536) + (p[2] * 256) + p[3]) >>> 0;
}

function v4Blocked(ip) {
  const n = v4ToInt(ip);
  for (const [base, bits] of V4_BLOCKS) {
    const b = v4ToInt(base);
    const mask = bits === 0 ? 0 : (0xFFFFFFFF << (32 - bits)) >>> 0;
    if (((n & mask) >>> 0) === ((b & mask) >>> 0)) return `${base}/${bits}`;
  }
  return null;
}

/** The 16 bytes of an IPv6 address, or null when it is not one. Accepts "::", "::ffff:1.2.3.4" and a zone ("%eth0"). */
export function v6Bytes(ip) {
  let s = ip.split('%')[0].toLowerCase();
  if (!net.isIPv6(s)) return null;
  let tail = [];
  const dot = s.lastIndexOf('.');
  if (dot > -1) {
    const colon = s.lastIndexOf(':');
    const v4 = s.slice(colon + 1);
    if (!net.isIPv4(v4)) return null;
    const q = v4.split('.').map(Number);
    tail = [((q[0] << 8) | q[1]).toString(16), ((q[2] << 8) | q[3]).toString(16)];
    s = s.slice(0, colon + 1) + '0:0';
  }
  const [head, rest] = s.split('::');
  const h = head ? head.split(':') : [];
  const r = rest !== undefined && rest !== '' ? rest.split(':') : [];
  let groups;
  if (rest === undefined) groups = h;
  else groups = [...h, ...Array(Math.max(0, 8 - h.length - r.length)).fill('0'), ...r];
  if (groups.length !== 8) return null;
  if (tail.length) { groups[6] = tail[0]; groups[7] = tail[1]; }
  const out = [];
  for (const g of groups) {
    const v = parseInt(g || '0', 16);
    if (!Number.isFinite(v) || v < 0 || v > 0xFFFF) return null;
    out.push(v >> 8, v & 255);
  }
  return out;
}

/**
 * Why an address is refused, or null when it is a public one. Accepts IPv4, IPv6 (any spelling), IPv4 inside IPv6
 * (mapped, NAT64, 6to4). Anything that is not an address is refused.
 */
export function blockedIp(ip) {
  if (typeof ip !== 'string') return 'не адрес';
  if (net.isIPv4(ip)) {
    const r = v4Blocked(ip);
    return r ? `внутренний адрес ${ip} (${r})` : null;
  }
  const b = v6Bytes(ip);
  if (!b) return 'не адрес: ' + String(ip).slice(0, 60);
  const allZero = (from, to) => b.slice(from, to).every((x) => x === 0);
  const embedded = (at) => `${b[at]}.${b[at + 1]}.${b[at + 2]}.${b[at + 3]}`;
  if (allZero(0, 15) && (b[15] === 0 || b[15] === 1)) return b[15] === 0 ? 'адрес ::' : 'локальный адрес ::1';
  if (allZero(0, 10) && b[10] === 0xff && b[11] === 0xff) return blockedIp(embedded(12)); // ::ffff:a.b.c.d
  if (allZero(0, 12)) return 'старый адрес IPv4 внутри IPv6'; // ::a.b.c.d
  if (b[0] === 0x00 && b[1] === 0x64 && b[2] === 0xff && b[3] === 0x9b && b[4] === 0 && b[5] === 0 && b[6] === 0 && b[7] === 0 && b[8] === 0 && b[9] === 0 && b[10] === 0 && b[11] === 0) return blockedIp(embedded(12)); // 64:ff9b::/96
  if (b[0] === 0x00 && b[1] === 0x64 && b[2] === 0xff && b[3] === 0x9b && b[4] === 0 && b[5] === 1) return 'внутренний адрес NAT64'; // 64:ff9b:1::/48
  if (b[0] === 0x01 && b[1] === 0x00 && allZero(2, 8)) return 'адрес 100::/64';
  if (b[0] === 0x20 && b[1] === 0x02) return blockedIp(embedded(2)) ? 'внутренний адрес в 6to4' : null; // 2002::/16
  if (b[0] === 0x20 && b[1] === 0x01 && b[2] === 0 && b[3] === 0) return 'адрес Teredo (2001::/32)';
  if (b[0] === 0x20 && b[1] === 0x01 && b[2] === 0x0d && b[3] === 0xb8) return 'адрес для примеров (2001:db8::/32)';
  if (b[0] === 0x20 && b[1] === 0x01 && b[2] === 0x00 && (b[3] & 0xf0) === 0x10) return 'адрес ORCHID (2001:10::/28)';
  if ((b[0] & 0xfe) === 0xfc) return `внутренний адрес ${ip} (fc00::/7)`;
  if (b[0] === 0xfe && (b[1] & 0xc0) === 0x80) return `локальный адрес ${ip} (fe80::/10)`;
  if (b[0] === 0xfe && (b[1] & 0xc0) === 0xc0) return `внутренний адрес ${ip} (fec0::/10)`;
  if (b[0] === 0xff) return `широковещательный адрес ${ip} (ff00::/8)`;
  return null;
}

/** Names that mean "this machine or this network" whatever DNS says. */
export function blockedHostName(host) {
  const h = String(host || '').toLowerCase().replace(/^\[|\]$/g, '').replace(/\.$/, '');
  if (h === '') return 'пустой адрес';
  if (net.isIP(h)) return null;
  if (h === 'localhost' || h.endsWith('.localhost')) return 'localhost';
  if (/\.(local|localdomain|internal|intranet|lan|home|corp|private|home\.arpa)$/.test(h)) return 'имя внутренней сети: ' + h;
  if (!h.includes('.')) return 'имя без точки (внутренняя сеть): ' + h;
  if (/^(0x[0-9a-f]+|[0-9]+)(\.(0x[0-9a-f]+|[0-9]+))*$/.test(h) && !net.isIPv4(h)) return 'адрес в необычной записи: ' + h; // 2130706433, 0x7f.1, 017700000001
  return null;
}

/**
 * Checks a URL before anything is sent: scheme, user info, port. Returns the reason or null.
 * @param {string|URL} url
 * @param {{allowPrivate?:boolean, ports?:number[], localPorts?:Set<number>}} o
 */
export function urlProblem(url, o = {}) {
  let u;
  try { u = url instanceof URL ? url : new URL(String(url)); } catch { return 'неверный адрес'; }
  if (u.protocol !== 'http:' && u.protocol !== 'https:') return `адреса вида ${u.protocol} не открываются (только http и https)`;
  if (u.username || u.password) return 'в адресе не должно быть логина и пароля';
  const port = u.port ? parseInt(u.port, 10) : (u.protocol === 'https:' ? 443 : 80);
  if (o.allowPrivate) return null;
  const isLocalOk = isLocalAllowed(u.hostname, port, o.localPorts);
  if (isLocalOk) return null;
  const name = blockedHostName(u.hostname);
  if (name) return 'адрес ведёт во внутреннюю сеть: ' + name;
  const bare = u.hostname.replace(/^\[|\]$/g, '');
  if (net.isIP(bare)) {
    const why = blockedIp(bare);
    if (why) return 'адрес ведёт во внутреннюю сеть: ' + why;
  }
  const ports = o.ports || [80, 443];
  if (!ports.includes(port)) return `порт ${port} не разрешён (только ${ports.join(' и ')})`;
  return null;
}

function isLocalAllowed(host, port, localPorts) {
  if (!localPorts || !localPorts.size) return false;
  const h = String(host).replace(/^\[|\]$/g, '');
  return (h === '127.0.0.1' || h === 'localhost' || h === '::1') && localPorts.has(port);
}

/**
 * Resolves a host and checks every address. A name is refused when ANY address of the answer is not public, so a mixed
 * answer cannot be used to reach an internal host. Returns the address to connect to.
 * @param {string} host
 * @param {{allowPrivate?:boolean, resolver?:(h:string)=>Promise<Array<{address:string,family:number}>>}} o
 * @returns {Promise<{ok:true,ip:string,family:number}|{ok:false,why:string}>}
 */
export async function resolveChecked(host, o = {}) {
  const bare = String(host || '').replace(/^\[|\]$/g, '').replace(/\.$/, '');
  if (net.isIP(bare)) {
    const why = o.allowPrivate ? null : blockedIp(bare);
    return why ? { ok: false, why } : { ok: true, ip: bare, family: net.isIPv6(bare) ? 6 : 4 };
  }
  if (!o.allowPrivate) {
    const why = blockedHostName(bare);
    if (why) return { ok: false, why };
  }
  let list;
  try {
    list = await (o.resolver || ((h) => dns.promises.lookup(h, { all: true, verbatim: true })))(bare);
  } catch (e) {
    return { ok: false, why: 'имя не найдено: ' + bare };
  }
  if (!Array.isArray(list) || list.length === 0) return { ok: false, why: 'имя не найдено: ' + bare };
  if (!o.allowPrivate) {
    for (const a of list) {
      const why = blockedIp(a.address);
      if (why) return { ok: false, why: `имя ${bare} ведёт во внутреннюю сеть: ${why}` };
    }
  }
  return { ok: true, ip: list[0].address, family: list[0].family || (net.isIPv6(list[0].address) ? 6 : 4) };
}

// ---- configuration shared by the fetches of this process --------------------------------------------------------

const config = { allowPrivate: false, localPorts: new Set(), resolver: null };

/** Sets the rules of this process: allowPrivate (command line only) and the ports of our own local servers. */
export function configureGuard({ allowPrivate = false, resolver = null } = {}) {
  config.allowPrivate = !!allowPrivate;
  config.resolver = resolver;
}
export const guardState = () => ({ allowPrivate: config.allowPrivate, localPorts: config.localPorts });
/** Our own local server (a folder shown to Chrome, the package for the self-check) may be opened at this port. */
export function allowLocalPort(port) { config.localPorts.add(port); }
export function releaseLocalPort(port) { config.localPorts.delete(port); }

// ---- Node-side fetch --------------------------------------------------------------------------------------------

/**
 * GET with the same rules as the proxy: every hop (redirects are followed by hand) is checked, the connection goes to the
 * address that was checked. Same answer shape as the old fetch wrapper.
 * @returns {Promise<{ok:boolean,status:number,body?:Buffer,contentType?:string,finalUrl?:string,why?:string}>}
 */
export async function safeGet(url, { maxBytes = 30 * 1048576, userAgent = '', referer = '', timeout = 40000, maxRedirects = 6, accept = '*/*' } = {}) {
  const started = Date.now();
  let current = url;
  for (let hop = 0; hop <= maxRedirects; hop++) {
    let u;
    try { u = new URL(current); } catch { return { ok: false, status: 0, why: 'неверный адрес' }; }
    const problem = urlProblem(u, { allowPrivate: config.allowPrivate, localPorts: config.localPorts });
    if (problem) return { ok: false, status: 0, why: 'запрещено: ' + problem };
    const target = isLocalAllowed(u.hostname, u.port ? parseInt(u.port, 10) : 80, config.localPorts)
      ? { ok: true, ip: '127.0.0.1', family: 4 }
      : await resolveChecked(u.hostname, { allowPrivate: config.allowPrivate, resolver: config.resolver });
    if (!target.ok) return { ok: false, status: 0, why: 'запрещено: ' + target.why };
    const left = timeout - (Date.now() - started);
    if (left <= 0) return { ok: false, status: 0, why: 'время вышло' };
    const r = await requestOnce(u, target, { maxBytes, userAgent, referer, timeout: left, accept });
    if (r.redirect) { current = new URL(r.redirect, u).href; continue; }
    if (r.ok) r.finalUrl = u.href;
    return r;
  }
  return { ok: false, status: 0, why: 'слишком много переходов' };
}

function requestOnce(u, target, { maxBytes, userAgent, referer, timeout, accept }) {
  return new Promise((resolve) => {
    const secure = u.protocol === 'https:';
    const lib = secure ? https : http;
    let done = false;
    const finish = (v) => { if (!done) { done = true; resolve(v); } };
    const headers = { host: u.host, 'user-agent': userAgent, accept, 'accept-language': 'ru-RU,ru;q=0.9', 'accept-encoding': 'gzip, deflate, br' };
    if (referer) headers.referer = referer;
    const req = lib.request({
      host: target.ip, port: u.port ? parseInt(u.port, 10) : (secure ? 443 : 80), method: 'GET', path: u.pathname + u.search, headers, agent: false,
      servername: secure && !net.isIP(u.hostname.replace(/^\[|\]$/g, '')) ? u.hostname : undefined, timeout,
    }, (res) => {
      const status = res.statusCode || 0;
      if (status >= 300 && status < 400 && res.headers.location) { res.resume(); return finish({ redirect: String(res.headers.location) }); }
      if (status < 200 || status >= 300) { res.resume(); return finish({ ok: false, status }); }
      const len = Number(res.headers['content-length'] || 0);
      if (len > maxBytes && !res.headers['content-encoding']) { res.destroy(); return finish({ ok: false, status: 413, why: 'слишком большой файл' }); }
      let stream = res;
      const enc = String(res.headers['content-encoding'] || '').toLowerCase();
      if (enc === 'gzip' || enc === 'x-gzip') stream = res.pipe(zlib.createGunzip());
      else if (enc === 'deflate') stream = res.pipe(zlib.createInflate());
      else if (enc === 'br') stream = res.pipe(zlib.createBrotliDecompress());
      const chunks = [];
      let size = 0;
      stream.on('data', (c) => {
        size += c.length;
        if (size > maxBytes) { res.destroy(); stream.destroy(); finish({ ok: false, status: 413, why: 'слишком большой файл' }); return; }
        chunks.push(c);
      });
      stream.on('end', () => finish({ ok: true, status, body: Buffer.concat(chunks), contentType: String(res.headers['content-type'] || '') }));
      stream.on('error', (e) => finish({ ok: false, status: 0, why: String(e && e.message || e).slice(0, 100) }));
      res.on('error', (e) => finish({ ok: false, status: 0, why: String(e && e.message || e).slice(0, 100) }));
    });
    req.on('timeout', () => { req.destroy(); finish({ ok: false, status: 0, why: 'время вышло' }); });
    req.on('error', (e) => finish({ ok: false, status: 0, why: String(e && e.message || e).slice(0, 100) }));
    req.end();
  });
}

// ---- the proxy of the browser -----------------------------------------------------------------------------------

const HOP = new Set(['connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'proxy-connection', 'te', 'trailer', 'transfer-encoding', 'upgrade']);

/**
 * A forward proxy on 127.0.0.1 for the browser. http addresses are fetched here, https addresses go through CONNECT
 * tunnels; both only to addresses that passed the checks above (or to the ports of our own local servers).
 *
 * @param {object} o
 * @param {boolean} [o.allowPrivate]
 * @param {number[]} [o.ports] ports of other hosts the browser may use (80 and 443 by default)
 * @param {(h:string)=>Promise<Array<{address:string,family:number}>>} [o.resolver] tests only
 * @param {(ip:string,port:number)=>net.Socket} [o.connect] tests only: how the connection to a checked address is made
 * @param {number} [o.maxBytes] stop everything after so many bytes in all
 * @param {number} [o.idleMs] a tunnel that is silent for so long is closed
 */
export async function startGuardProxy(o = {}) {
  const user = 'ag' + randomBytes(4).toString('hex');
  const pass = randomBytes(12).toString('hex');
  const expected = 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64');
  const ports = o.ports || [80, 443];
  const localPorts = new Set();
  const stats = { requests: 0, bytes: 0, blocked: [], overLimit: false };
  const sockets = new Set();
  const connect = o.connect || ((ip, port) => net.connect({ host: ip, port }));
  const idleMs = o.idleMs || 120000;
  const maxBytes = o.maxBytes || Infinity;

  const block = (host, port, why) => {
    if (stats.blocked.length < 100) stats.blocked.push({ host: String(host).slice(0, 100), port, why, at: new Date().toISOString() });
  };
  const authorized = (req) => (req.headers['proxy-authorization'] || '') === expected;
  const count = (n) => {
    stats.bytes += n;
    if (stats.bytes > maxBytes) stats.overLimit = true;
    return !stats.overLimit;
  };
  /** The address to connect to for host:port, or null (and a note) when it is refused. */
  async function target(host, port) {
    if (isLocalAllowed(host, port, localPorts)) return { ip: '127.0.0.1' };
    if (!o.allowPrivate && !ports.includes(port)) { block(host, port, `порт ${port} не разрешён`); return null; }
    const r = await resolveChecked(host, { allowPrivate: o.allowPrivate, resolver: o.resolver });
    if (!r.ok) { block(host, port, r.why); return null; }
    return { ip: r.ip };
  }

  const server = http.createServer(async (req, res) => {
    if (!authorized(req)) { res.writeHead(407, { 'Proxy-Authenticate': 'Basic realm="agecms"', Connection: 'close' }); res.end(); return; }
    stats.requests++;
    let u;
    try { u = new URL(req.url); } catch { res.writeHead(400, { Connection: 'close' }); res.end(); return; }
    if (u.protocol !== 'http:' || u.username || u.password) { block(req.url.slice(0, 100), 0, 'не http'); res.writeHead(403, { Connection: 'close' }); res.end('forbidden'); return; }
    const port = u.port ? parseInt(u.port, 10) : 80;
    const t = await target(u.hostname, port);
    if (!t) { res.writeHead(403, { 'Content-Type': 'text/plain', Connection: 'close' }); res.end('Forbidden by AgeCMS capture guard'); return; }
    if (stats.overLimit) { res.writeHead(507, { Connection: 'close' }); res.end(); return; }
    const headers = {};
    for (const [k, v] of Object.entries(req.headers)) if (!HOP.has(k.toLowerCase())) headers[k] = v;
    headers.host = u.host;
    const up = http.request({ host: t.ip, port, method: req.method, path: u.pathname + u.search, headers, agent: false }, (r) => {
      const h = {};
      for (const [k, v] of Object.entries(r.headers)) if (!HOP.has(k.toLowerCase())) h[k] = v;
      res.writeHead(r.statusCode || 502, h);
      r.on('data', (c) => { if (!count(c.length)) { r.destroy(); res.destroy(); return; } res.write(c); });
      r.on('end', () => res.end());
      r.on('error', () => res.destroy());
    });
    up.setTimeout(60000, () => up.destroy());
    up.on('error', () => { if (!res.headersSent) { res.writeHead(502, { Connection: 'close' }); res.end(); } else res.destroy(); });
    req.pipe(up);
  });
  server.on('connection', (s) => { sockets.add(s); s.on('close', () => sockets.delete(s)); });
  server.on('connect', async (req, client, head) => {
    client.on('error', () => {});
    if (!authorized(req)) { client.end('HTTP/1.1 407 Proxy Authentication Required\r\nProxy-Authenticate: Basic realm="agecms"\r\nConnection: close\r\n\r\n'); return; }
    stats.requests++;
    const m = /^\[?([^\]]+?)\]?:(\d+)$/.exec(req.url || '');
    if (!m) { client.end('HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n'); return; }
    const host = m[1];
    const port = parseInt(m[2], 10);
    const t = await target(host, port);
    if (!t) { client.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n'); return; }
    if (stats.overLimit) { client.end('HTTP/1.1 507 Insufficient Storage\r\nConnection: close\r\n\r\n'); return; }
    const up = connect(t.ip, port);
    sockets.add(up);
    up.on('close', () => sockets.delete(up));
    up.on('error', () => { if (!client.destroyed) client.end('HTTP/1.1 502 Bad Gateway\r\nConnection: close\r\n\r\n'); });
    up.setTimeout(idleMs, () => up.destroy());
    client.setTimeout(idleMs, () => client.destroy());
    up.once('connect', () => {
      client.write('HTTP/1.1 200 Connection Established\r\n\r\n');
      if (head && head.length) up.write(head);
      up.on('data', (c) => { if (!count(c.length)) { up.destroy(); client.destroy(); return; } client.write(c); });
      client.on('data', (c) => up.write(c));
      up.on('end', () => client.end());
      client.on('end', () => up.end());
      client.on('close', () => up.destroy());
    });
    // A test connector may hand over a socket that is already connected.
    if (up.connecting === false && !up.destroyed) up.emit('connect');
  });
  server.on('clientError', (e, s) => { try { s.destroy(); } catch { /* gone */ } });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;
  return {
    port, user, pass, stats,
    server: `http://127.0.0.1:${port}`,
    allowLocal(p) { localPorts.add(p); allowLocalPort(p); },
    releaseLocal(p) { localPorts.delete(p); releaseLocalPort(p); },
    close() { for (const s of sockets) { try { s.destroy(); } catch { /* gone */ } } server.close(); },
  };
}
