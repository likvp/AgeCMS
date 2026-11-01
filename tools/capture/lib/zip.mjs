// A minimal zip writer (deflate) for the finished package, no dependencies.
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';

const crcTable = (() => {
  const t = new Uint32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    t[n] = c >>> 0;
  }
  return t;
})();

function crc32(buf) {
  if (typeof zlib.crc32 === 'function') return zlib.crc32(buf) >>> 0;
  let c = 0xffffffff;
  for (let i = 0; i < buf.length; i++) c = crcTable[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
}

function walk(dir, base = dir, out = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, e.name);
    if (e.isDirectory()) walk(full, base, out);
    else if (e.isFile()) out.push([path.relative(base, full).split(path.sep).join('/'), full]);
  }
  return out;
}

/** Packs a folder into a zip file. Files are streamed one by one, so a big package does not need memory for all of it. */
export function zipFolder(dir, target) {
  const fd = fs.openSync(target, 'w');
  let offset = 0;
  const central = [];
  const write = (buf) => { fs.writeSync(fd, buf); offset += buf.length; };
  const files = walk(dir).sort((a, b) => (a[0] === 'manifest.json' ? -1 : b[0] === 'manifest.json' ? 1 : a[0].localeCompare(b[0])));
  for (const [name, full] of files) {
    const data = fs.readFileSync(full);
    const stored = /\.(png|jpe?g|gif|webp|avif|woff2?|mp4|webm|mp3|zip|ogg|m4a)$/i.test(name);
    const comp = stored ? data : zlib.deflateRawSync(data, { level: 6 });
    const method = stored || comp.length >= data.length ? 0 : 8;
    const body = method === 0 ? data : comp;
    const nameBuf = Buffer.from(name, 'utf8');
    const crc = crc32(data);
    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0);
    local.writeUInt16LE(20, 4);
    local.writeUInt16LE(0x0800, 6); // utf-8 names
    local.writeUInt16LE(method, 8);
    local.writeUInt16LE(0, 10);
    local.writeUInt16LE(0x21, 12); // 1980-01-01
    local.writeUInt32LE(crc, 14);
    local.writeUInt32LE(body.length, 18);
    local.writeUInt32LE(data.length, 22);
    local.writeUInt16LE(nameBuf.length, 26);
    local.writeUInt16LE(0, 28);
    const start = offset;
    write(local); write(nameBuf); write(body);
    central.push({ nameBuf, crc, csize: body.length, size: data.length, method, start });
  }
  const cdStart = offset;
  for (const c of central) {
    const h = Buffer.alloc(46);
    h.writeUInt32LE(0x02014b50, 0);
    h.writeUInt16LE(20, 4);
    h.writeUInt16LE(20, 6);
    h.writeUInt16LE(0x0800, 8);
    h.writeUInt16LE(c.method, 10);
    h.writeUInt16LE(0, 12);
    h.writeUInt16LE(0x21, 14);
    h.writeUInt32LE(c.crc, 16);
    h.writeUInt32LE(c.csize, 20);
    h.writeUInt32LE(c.size, 24);
    h.writeUInt16LE(c.nameBuf.length, 28);
    h.writeUInt32LE(0, 38);
    h.writeUInt32LE(c.start, 42);
    write(h); write(c.nameBuf);
  }
  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0);
  end.writeUInt16LE(central.length, 8);
  end.writeUInt16LE(central.length, 10);
  end.writeUInt32LE(offset - cdStart, 12);
  end.writeUInt32LE(cdStart, 16);
  write(end);
  fs.closeSync(fd);
  return files.length;
}
