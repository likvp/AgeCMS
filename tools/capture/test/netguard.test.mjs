import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import net from 'node:net';
import { blockedIp, blockedHostName, urlProblem, resolveChecked, safeGet, startGuardProxy, configureGuard, allowLocalPort, releaseLocalPort } from '../lib/netguard.mjs';

const listen = (server) => new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server.address().port)));

test('addresses of the internal network are refused, public ones are not', () => {
  for (const ip of ['127.0.0.1', '127.255.255.254', '10.0.0.1', '10.255.255.255', '172.16.0.1', '172.31.255.255', '192.168.1.1', '169.254.169.254', '100.64.0.1', '100.127.255.255',
    '0.0.0.0', '198.18.0.1', '224.0.0.1', '255.255.255.255', '::', '::1', 'fe80::1', 'fe80::abcd%eth0', 'fc00::1', 'fd12:3456::1', 'ff02::1',
    '::ffff:127.0.0.1', '::ffff:7f00:1', '::ffff:169.254.169.254', '::ffff:10.1.2.3', '64:ff9b::7f00:1', '2002:7f00:1::1', '2001:db8::1', '2001::1']) {
    assert.ok(blockedIp(ip), `${ip} must be refused`);
  }
  for (const ip of ['8.8.8.8', '93.184.216.34', '172.15.255.255', '172.32.0.1', '100.63.255.255', '100.128.0.1', '2606:4700:4700::1111', '::ffff:8.8.8.8', '2a00:1450:4001:81b::200e']) {
    assert.equal(blockedIp(ip), null, `${ip} must be allowed`);
  }
  assert.ok(blockedIp('not an address'));
});

test('names that mean this machine are refused whatever DNS says', () => {
  for (const h of ['localhost', 'LOCALHOST.', 'app.localhost', 'printer.local', 'db.internal', 'metadata.google.internal', 'intranet', 'router.lan', '2130706433', '0x7f.1', '017700000001']) {
    assert.ok(blockedHostName(h), `${h} must be refused`);
  }
  for (const h of ['example.com', 'face.bd', 'dead.beef', 'xn--80aswg.xn--p1ai', '8.8.8.8']) assert.equal(blockedHostName(h), null, h);
});

test('an address is checked before it is opened: scheme, port, user info', () => {
  assert.match(urlProblem('file:///etc/passwd'), /file:/);
  assert.match(urlProblem('chrome://version'), /chrome:/);
  assert.match(urlProblem('ftp://example.com/x'), /ftp:/);
  assert.match(urlProblem('http://user:pass@example.com/'), /логина/);
  assert.match(urlProblem('http://example.com:22/'), /порт 22/);
  assert.match(urlProblem('http://127.0.0.1/'), /внутреннюю сеть/);
  assert.match(urlProblem('http://[::1]/'), /внутреннюю сеть/);
  assert.match(urlProblem('https://169.254.169.254/latest/meta-data/'), /внутреннюю сеть/);
  assert.match(urlProblem('http://localhost:80/'), /внутреннюю сеть/);
  assert.equal(urlProblem('https://example.com/a?b=1'), null);
  assert.equal(urlProblem('http://127.0.0.1:8080/', { allowPrivate: true }), null);
});

test('a name with one private address among public ones is refused', async () => {
  const resolver = async () => [{ address: '93.184.216.34', family: 4 }, { address: '10.0.0.5', family: 4 }];
  const r = await resolveChecked('mixed.test', { resolver });
  assert.equal(r.ok, false);
  assert.match(r.why, /внутреннюю сеть/);
  const ok = await resolveChecked('fine.test', { resolver: async () => [{ address: '93.184.216.34', family: 4 }] });
  assert.deepEqual([ok.ok, ok.ip], [true, '93.184.216.34']);
  const none = await resolveChecked('gone.test', { resolver: async () => { throw new Error('ENOTFOUND'); } });
  assert.equal(none.ok, false);
});

test('safeGet does not follow a redirect to the internal network and never connects there', async () => {
  let hits = 0;
  const trap = http.createServer((req, res) => { hits++; res.end('secret'); });
  const trapPort = await listen(trap);
  const front = http.createServer((req, res) => {
    if (req.url === '/meta') { res.writeHead(302, { Location: 'http://169.254.169.254/latest/meta-data/' }); res.end(); return; }
    if (req.url === '/trap') { res.writeHead(302, { Location: `http://127.0.0.1:${trapPort}/` }); res.end(); return; }
    if (req.url === '/loop') { res.writeHead(302, { Location: '/loop' }); res.end(); return; }
    res.end('front ok');
  });
  const frontPort = await listen(front);
  configureGuard({ allowPrivate: false });
  allowLocalPort(frontPort); // the only local server the tool may open
  try {
    const ok = await safeGet(`http://127.0.0.1:${frontPort}/`, { userAgent: 't' });
    assert.equal(ok.ok, true);
    assert.equal(ok.body.toString(), 'front ok');
    const meta = await safeGet(`http://127.0.0.1:${frontPort}/meta`, { userAgent: 't' });
    assert.equal(meta.ok, false);
    assert.match(meta.why, /запрещено/);
    const toTrap = await safeGet(`http://127.0.0.1:${frontPort}/trap`, { userAgent: 't' });
    assert.equal(toTrap.ok, false);
    assert.equal(hits, 0, 'the internal server must not be touched');
    const loop = await safeGet(`http://127.0.0.1:${frontPort}/loop`, { userAgent: 't', maxRedirects: 3 });
    assert.match(loop.why, /много переходов/);
    const direct = await safeGet(`http://127.0.0.1:${trapPort}/`, { userAgent: 't' });
    assert.equal(direct.ok, false);
    assert.equal(hits, 0);
    const file = await safeGet('file:///etc/passwd', { userAgent: 't' });
    assert.equal(file.ok, false);
  } finally {
    releaseLocalPort(frontPort);
    trap.close();
    front.close();
  }
});

test('safeGet stops a body that is bigger than allowed', async () => {
  const big = http.createServer((req, res) => { res.write(Buffer.alloc(200000, 1)); res.end(Buffer.alloc(200000, 1)); });
  const port = await listen(big);
  configureGuard({ allowPrivate: false });
  allowLocalPort(port);
  try {
    const r = await safeGet(`http://127.0.0.1:${port}/`, { userAgent: 't', maxBytes: 100000 });
    assert.equal(r.ok, false);
    assert.equal(r.status, 413);
  } finally {
    releaseLocalPort(port);
    big.close();
  }
});

/** Sends one request to the proxy over a raw socket and returns the first line of the answer. */
function viaProxy(proxy, head, { auth = true } = {}) {
  return new Promise((resolve) => {
    const s = net.connect(proxy.port, '127.0.0.1');
    let data = '';
    s.on('data', (c) => { data += c.toString(); if (/\r\n\r\n/.test(data)) { s.destroy(); } });
    s.on('close', () => resolve(data.split('\r\n')[0]));
    s.on('error', () => resolve('error'));
    const cred = auth ? `Proxy-Authorization: Basic ${Buffer.from(`${proxy.user}:${proxy.pass}`).toString('base64')}\r\n` : '';
    s.write(head.replace('\r\n\r\n', '\r\n' + cred + '\r\n'));
    setTimeout(() => s.destroy(), 3000);
  });
}

test('the proxy of the browser refuses localhost, private ranges and metadata, without connecting', async () => {
  let hits = 0;
  const trap = http.createServer((req, res) => { hits++; res.end('secret'); });
  const trapPort = await listen(trap);
  const dialed = [];
  const proxy = await startGuardProxy({ connect: (ip, port) => { dialed.push(`${ip}:${port}`); const s = net.connect(trapPort, '127.0.0.1'); return s; } });
  try {
    assert.match(await viaProxy(proxy, `GET http://127.0.0.1:${trapPort}/ HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n`), /403/);
    assert.match(await viaProxy(proxy, `GET http://localhost:${trapPort}/ HTTP/1.1\r\nHost: localhost\r\n\r\n`), /403/);
    assert.match(await viaProxy(proxy, 'GET http://169.254.169.254/latest/meta-data/ HTTP/1.1\r\nHost: x\r\n\r\n'), /403/);
    assert.match(await viaProxy(proxy, 'GET http://[::1]/ HTTP/1.1\r\nHost: x\r\n\r\n'), /403/);
    assert.match(await viaProxy(proxy, 'GET http://10.1.2.3/ HTTP/1.1\r\nHost: x\r\n\r\n'), /403/);
    assert.match(await viaProxy(proxy, 'GET http://192.168.0.1:80/ HTTP/1.1\r\nHost: x\r\n\r\n'), /403/);
    assert.match(await viaProxy(proxy, 'CONNECT 127.0.0.1:443 HTTP/1.1\r\nHost: x\r\n\r\n'), /403/);
    assert.match(await viaProxy(proxy, 'CONNECT [::1]:443 HTTP/1.1\r\nHost: x\r\n\r\n'), /403/);
    assert.match(await viaProxy(proxy, 'CONNECT 169.254.169.254:443 HTTP/1.1\r\nHost: x\r\n\r\n'), /403/);
    assert.match(await viaProxy(proxy, 'CONNECT metadata.google.internal:443 HTTP/1.1\r\nHost: x\r\n\r\n'), /403/);
    assert.match(await viaProxy(proxy, 'CONNECT example.com:22 HTTP/1.1\r\nHost: x\r\n\r\n'), /403/);
    assert.match(await viaProxy(proxy, 'GET file:///etc/passwd HTTP/1.1\r\nHost: x\r\n\r\n'), /403|400/);
    assert.match(await viaProxy(proxy, `GET http://127.0.0.1:${trapPort}/ HTTP/1.1\r\nHost: x\r\n\r\n`, { auth: false }), /407/);
    assert.equal(hits, 0, 'nothing reached the internal server');
    assert.deepEqual(dialed, [], 'no connection was attempted');
    assert.ok(proxy.stats.blocked.length >= 10);
    // A local server is reachable only after it has been named as ours.
    proxy.allowLocal(trapPort);
    assert.match(await viaProxy(proxy, `GET http://127.0.0.1:${trapPort}/ HTTP/1.1\r\nHost: x\r\n\r\n`), /200/);
    assert.equal(hits, 1);
    proxy.releaseLocal(trapPort);
    assert.match(await viaProxy(proxy, `GET http://127.0.0.1:${trapPort}/ HTTP/1.1\r\nHost: x\r\n\r\n`), /403/);
  } finally {
    proxy.close();
    trap.close();
  }
});

test('DNS rebinding: the name is resolved once and the connection goes to the address that was checked', async () => {
  let asked = 0;
  const resolver = async () => (++asked === 1 ? [{ address: '93.184.216.34', family: 4 }] : [{ address: '127.0.0.1', family: 4 }]);
  const sink = net.createServer((c) => c.end());
  const sinkPort = await new Promise((r) => sink.listen(0, '127.0.0.1', () => r(sink.address().port)));
  const dialed = [];
  const proxy = await startGuardProxy({ resolver, connect: (ip, port) => { dialed.push(ip); return net.connect(sinkPort, '127.0.0.1'); } });
  try {
    const first = await viaProxy(proxy, 'CONNECT rebind.test:443 HTTP/1.1\r\nHost: x\r\n\r\n');
    assert.match(first, /200/);
    assert.deepEqual(dialed, ['93.184.216.34']);
    assert.equal(asked, 1, 'one resolution per request');
    // The next request gets the private answer and is refused.
    const second = await viaProxy(proxy, 'CONNECT rebind.test:443 HTTP/1.1\r\nHost: x\r\n\r\n');
    assert.match(second, /403/);
    assert.deepEqual(dialed, ['93.184.216.34']);
  } finally {
    proxy.close();
    sink.close();
  }
});

test('the proxy stops everything when more bytes pass than allowed', async () => {
  const big = http.createServer((req, res) => { res.end(Buffer.alloc(5000, 1)); });
  const port = await listen(big);
  const proxy = await startGuardProxy({ maxBytes: 4000 });
  proxy.allowLocal(port);
  try {
    await viaProxy(proxy, `GET http://127.0.0.1:${port}/ HTTP/1.1\r\nHost: x\r\n\r\n`);
    await new Promise((r) => setTimeout(r, 100));
    assert.equal(proxy.stats.overLimit, true);
    assert.match(await viaProxy(proxy, `GET http://127.0.0.1:${port}/ HTTP/1.1\r\nHost: x\r\n\r\n`), /507/);
  } finally {
    proxy.close();
    big.close();
  }
});
