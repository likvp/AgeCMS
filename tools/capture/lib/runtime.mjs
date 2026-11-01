// How a capture lives as a background process: a status file the site reads (progress, heartbeat, result), limits that stop a
// run that takes too long, too much memory or too much disk, and the error codes with their messages.
import fs from 'node:fs';
import path from 'node:path';
import { execFile } from 'node:child_process';

/** Error codes of a run: the code goes to the status file and to the exit code, the message is for a person. */
export const EXIT = { ok: 0, error: 1, usage: 2, no_chrome: 3, refused: 4, timeout: 5, memory: 6, disk: 7, size: 8, root_no_sandbox: 3, cancelled: 143, node: 9 };

export class CaptureError extends Error {
  constructor(code, message) {
    super(message);
    this.code = code;
  }
}

/**
 * The status file. `state` is running, done, failed or cancelled; `updated` moves every few seconds while the process is alive
 * (the site treats a run whose heartbeat stopped as interrupted). Writes are atomic: the site never reads half a file.
 */
export class Progress {
  /**
   * @param {string|null} file
   * @param {object} base fields that never change (token, mode, limits)
   */
  constructor(file, base = {}) {
    this.file = file;
    this.data = { state: 'running', phase: 'start', message: 'Запускаю захват', pid: process.pid, started: Date.now(), updated: Date.now(), pages: 0, files: 0, bytes: 0, warnings: [], blocked: 0, ...base };
    this.timer = null;
    this.dirty = true;
    this.lastWrite = 0;
  }

  set(patch) {
    Object.assign(this.data, patch);
    this.data.updated = Date.now();
    this.dirty = true;
    if (Date.now() - this.lastWrite > 700) this.flush();
  }

  warn(text) {
    if (!this.data.warnings.includes(text) && this.data.warnings.length < 20) this.data.warnings.push(text);
    this.dirty = true;
  }

  flush() {
    this.lastWrite = Date.now();
    if (!this.file) return;
    try {
      const tmp = this.file + '.tmp';
      fs.writeFileSync(tmp, JSON.stringify(this.data));
      fs.renameSync(tmp, this.file);
      this.dirty = false;
    } catch { /* a full disk must not kill the capture; the site will see the stale heartbeat */ }
  }

  start() {
    this.flush();
    this.timer = setInterval(() => { this.data.updated = Date.now(); this.flush(); }, 3000);
    this.timer.unref();
  }

  /** Writes the final state and stops the heartbeat. */
  end(state, patch = {}) {
    if (this.timer) clearInterval(this.timer);
    this.timer = null;
    this.set({ state, finished: Date.now(), ...patch });
    this.flush();
  }
}

/** Resident memory in MB of this process and everything it started (Chrome with its helper processes). */
export function treeRssMb(rootPid = process.pid) {
  return new Promise((resolve) => {
    execFile('ps', ['-A', '-o', 'pid=,ppid=,rss='], { timeout: 5000, maxBuffer: 8 * 1024 * 1024 }, (err, out) => {
      if (err) { resolve(process.memoryUsage().rss / 1048576); return; }
      const kids = new Map();
      const rss = new Map();
      for (const line of String(out).split('\n')) {
        const m = /^\s*(\d+)\s+(\d+)\s+(\d+)\s*$/.exec(line);
        if (!m) continue;
        const [pid, ppid, kb] = [Number(m[1]), Number(m[2]), Number(m[3])];
        rss.set(pid, kb);
        if (!kids.has(ppid)) kids.set(ppid, []);
        kids.get(ppid).push(pid);
      }
      let total = 0;
      const stack = [rootPid];
      const seen = new Set();
      while (stack.length) {
        const p = stack.pop();
        if (seen.has(p)) continue;
        seen.add(p);
        total += rss.get(p) || 0;
        for (const c of kids.get(p) || []) stack.push(c);
      }
      resolve(total / 1024);
    });
  });
}

/** Free space in MB of the disk that holds a folder, or null when this Node cannot tell. */
export function freeMb(dir) {
  try {
    if (typeof fs.statfsSync !== 'function') return null;
    const s = fs.statfsSync(dir);
    return (Number(s.bavail) * Number(s.bsize)) / 1048576;
  } catch { return null; }
}

/**
 * Looks at the run every few seconds and stops it (onFail(code, message)) when it is out of time, memory or disk.
 * @param {{maxSeconds?:number,maxMemMb?:number,minFreeMb?:number,dir:string,onFail:(code:string,message:string)=>void,proxyStats?:()=>{overLimit:boolean}}} o
 */
export function startWatchdog(o) {
  const t0 = Date.now();
  let memoryBusy = false;
  const timer = setInterval(async () => {
    if (o.maxSeconds && (Date.now() - t0) / 1000 > o.maxSeconds) {
      o.onFail('timeout', `Захват не уложился в ${Math.round(o.maxSeconds / 60)} мин и остановлен. Уменьшите число страниц или увеличьте время в настройках модуля.`);
      return;
    }
    if (o.minFreeMb) {
      const f = freeMb(o.dir);
      if (f !== null && f < o.minFreeMb) {
        o.onFail('disk', `На диске осталось меньше ${o.minFreeMb} МБ свободного места, захват остановлен.`);
        return;
      }
    }
    if (o.proxyStats && o.proxyStats().overLimit) {
      o.onFail('size', 'Объём загруженного превысил предел захвата, он остановлен. Уменьшите число страниц или увеличьте предел в настройках модуля.');
      return;
    }
    if (o.maxMemMb && !memoryBusy) {
      memoryBusy = true;
      const mb = await treeRssMb();
      memoryBusy = false;
      if (mb > o.maxMemMb) o.onFail('memory', `Захват занял больше ${o.maxMemMb} МБ памяти и остановлен. Уменьшите число страниц или увеличьте предел в настройках модуля.`);
    }
  }, 4000);
  timer.unref();
  return () => clearInterval(timer);
}

/** Atomic JSON write helper for small result files. */
export function writeJson(file, data) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  const tmp = file + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(data));
  fs.renameSync(tmp, file);
}
