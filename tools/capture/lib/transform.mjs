// Functions that run INSIDE the browser (Playwright serializes them), so each one is self-contained.
// snapshotDom: the live page as a string. transform: HTML or CSS text with every address of a captured file replaced.

/** Final DOM of the page the visitor sees, as HTML text (scripts are not run again by this step). */
export function snapshotDom() {
  const doc = document;
  const clone = doc.documentElement.cloneNode(true);
  // Rules added by scripts (CSS in JS) live only in the CSSOM: write them out where the style element has no text.
  const live = doc.querySelectorAll('style');
  const copy = clone.querySelectorAll('style');
  live.forEach((el, i) => {
    try {
      if (el.sheet && copy[i] && (!el.textContent || !el.textContent.trim()) && el.sheet.cssRules.length > 0) {
        copy[i].textContent = Array.from(el.sheet.cssRules).map((r) => r.cssText).join('\n');
      }
    } catch (e) { /* cross-origin sheet: nothing to read */ }
  });
  if (doc.adoptedStyleSheets && doc.adoptedStyleSheets.length) {
    const st = doc.createElement('style');
    try {
      st.textContent = doc.adoptedStyleSheets.map((s) => Array.from(s.cssRules).map((r) => r.cssText).join('\n')).join('\n');
      (clone.querySelector('head') || clone).appendChild(st);
    } catch (e) { /* ignore */ }
  }
  const liveImgs = doc.querySelectorAll('img');
  const copyImgs = clone.querySelectorAll('img');
  liveImgs.forEach((img, i) => {
    const c = copyImgs[i];
    if (!c) return;
    const cur = img.currentSrc;
    const attr = c.getAttribute('src') || '';
    if (cur && (attr === '' || attr.startsWith('data:'))) c.setAttribute('src', cur);
  });
  // The state of open/closed details and input values does not matter for a copy; the scroll position is reset.
  const dt = doc.doctype ? '<!DOCTYPE ' + doc.doctype.name + '>' : '<!DOCTYPE html>';
  return dt + '\n' + clone.outerHTML;
}

/**
 * @param {object} args
 * kind: 'html' | 'css' | 'js'; text; baseUrl; map {absoluteUrl: "host/path"}; failed [absoluteUrl] (could not be fetched);
 * sentinel; siteHost; trackerUrls [[label, pattern]]; trackerInline [[label, regexp source]]; framePrefixes [..];
 * mode 'source'|'dom'; keepScripts bool
 */
export function transform(args) {
  const SENT = args.sentinel;
  const map = args.map;
  const failed = new Set(args.failed || []);
  const missing = new Set();
  const trackers = new Set();
  const notes = [];
  const lowerTrackers = args.trackerUrls.map(([l, p]) => [l, p, p.includes('.*') ? new RegExp(p) : null]);
  const inlineTrackers = args.trackerInline.map(([l, p]) => [l, new RegExp(p, 'i')]);
  const bare = (h) => h.toLowerCase().replace(/^www\./, '');
  const siteHost = bare(args.siteHost || '');

  const trackerOf = (u) => {
    const x = String(u).toLowerCase();
    for (const [l, p, re] of lowerTrackers) if (re ? re.test(x) : x.includes(p)) return l;
    return '';
  };
  const resolve = (raw, base) => {
    try { return new URL(raw, base || args.baseUrl).href; } catch (e) { return null; }
  };
  const skipRef = (v) => v === '' || /^(data:|blob:|javascript:|about:|mailto:|tel:|sms:|#|\{|%7B|\$\{|<)/i.test(v);
  const FILE_EXT = /\.(css|js|mjs|json|svg|png|jpe?g|gif|webp|avif|ico|bmp|woff2?|ttf|otf|eot|mp4|webm|mp3|m4a|ogg|ogv|wav|pdf|vtt|docx?|xlsx?|pptx?|odt|rtf|zip)(\?|#|$)/i;

  /** Address of the saved copy of a file, or null. With wantFetch an unknown address is remembered for downloading. */
  const local = (raw, base, wantFetch) => {
    raw = String(raw).trim();
    if (skipRef(raw) || raw.startsWith(SENT)) return null;
    const abs = resolve(raw, base);
    if (!abs || !/^https?:/i.test(abs)) return null;
    const hashAt = abs.indexOf('#');
    const key = hashAt < 0 ? abs : abs.slice(0, hashAt);
    const hash = hashAt < 0 ? '' : abs.slice(hashAt);
    const found = map[key];
    if (found) return SENT + found + hash;
    const t = trackerOf(key);
    if (t) { trackers.add(t); return null; }
    if (wantFetch && !failed.has(key)) missing.add(key);
    return null;
  };

  /** url(...) and @import in CSS. Files that could not be taken become empty data addresses (no request to the old site). */
  const css = (text, base) => {
    const fix = (raw) => {
      const v = raw.trim();
      if (skipRef(v) || v.startsWith(SENT)) return null;
      const l = local(v, base, true);
      if (l) return l;
      const abs = resolve(v, base);
      if (abs && /^https?:/i.test(abs)) {
        const key = abs.split('#')[0];
        if (failed.has(key) || trackerOf(key)) return 'data:,';
      }
      return null;
    };
    text = text.replace(/url\(\s*(?:"([^"]*)"|'([^']*)'|([^)\s"'][^)]*?))\s*\)/gi, (m, d, s, u) => {
      const r = fix(d ?? s ?? u ?? '');
      return r === null ? m : 'url("' + r + '")';
    });
    text = text.replace(/@import\s+(?:"([^"]+)"|'([^']+)')/gi, (m, d, s) => {
      const r = fix(d ?? s ?? '');
      return r === null ? m : '@import url("' + r + '")';
    });
    return text;
  };

  /** Absolute addresses inside any text (inline JSON, scripts). Only files that were saved are replaced. */
  const inText = (text, wantFetch) => {
    if (!text || (text.indexOf('//') < 0 && text.indexOf('\\/\\/') < 0)) return text;
    // A counter named without the scheme ("//www.clicktex.ru/code/1"): the same empty file.
    text = text.replace(/(["'`])\/\/([^\s"'`<>]+)\1/g, (m, q, rest) => (trackerOf('https://' + rest) ? q + SENT + '_blocked/empty.js' + q : m));
    return text.replace(/https?:(?:\\\/|\/){2}(?:[^\s"'`<>()\[\]{},;|^\\]|\\\/)+/g, (m) => {
      const escaped = m.includes('\\/');
      const plain = escaped ? m.replace(/\\\//g, '/') : m;
      const l = local(plain, args.baseUrl, wantFetch && FILE_EXT.test(plain));
      if (!l) {
        // A counter or a social widget named by a script of the site: the address leads to an empty file of the copy,
        // so the script does not break and nothing leaves the site.
        if (trackerOf(plain)) { const stub = SENT + '_blocked/empty.js'; return escaped ? stub.replace(/\//g, '\\/') : stub; }
        return m;
      }
      return escaped ? l.replace(/\//g, '\\/') : l;
    });
  };

  const srcset = (value, base) => {
    const parts = value.split(/,(?=\s|$)|,\s+(?=\S)/).map((p) => p.trim()).filter(Boolean);
    const out = [];
    for (const p of parts) {
      const m = /^(\S+)(\s+.*)?$/.exec(p);
      if (!m) continue;
      if (skipRef(m[1])) { out.push(p); continue; }
      const l = local(m[1], base, false);
      if (l) out.push(l + (m[2] || ''));
    }
    return out.join(', ');
  };

  if (args.kind === 'css') {
    return { text: css(args.text, args.baseUrl), missing: [...missing], trackers: [...trackers] };
  }
  if (args.kind === 'js') {
    return { text: inText(args.text, false), missing: [...missing], trackers: [...trackers] };
  }

  // ---- HTML ----------------------------------------------------------------------------------------------------
  const doc = new DOMParser().parseFromString(args.text, 'text/html');
  const html = doc.documentElement;
  const head = doc.head;
  const body = doc.body;
  let base = args.baseUrl;
  const baseEl = doc.querySelector('base[href]');
  if (baseEl) { const b = resolve(baseEl.getAttribute('href'), args.baseUrl); if (b) base = b; }

  const meta = { title: '', description: '', og_image: '', og_title: '', canonical: '', noindex: false, lang: html.getAttribute('lang') || '', generator: '', favicon: '' };
  const titleEl = doc.querySelector('head > title') || doc.querySelector('title');
  meta.title = titleEl ? titleEl.textContent.replace(/\s+/g, ' ').trim() : '';
  const metaContent = (sel) => { const e = doc.querySelector(sel); return e ? (e.getAttribute('content') || '').trim() : ''; };
  meta.description = metaContent('meta[name="description" i]');
  meta.og_image = metaContent('meta[property="og:image" i]') || metaContent('meta[name="twitter:image" i]');
  meta.og_title = metaContent('meta[property="og:title" i]');
  meta.generator = metaContent('meta[name="generator" i]');
  const can = doc.querySelector('link[rel="canonical" i]');
  meta.canonical = can ? (can.getAttribute('href') || '') : '';
  meta.noindex = /noindex/i.test(metaContent('meta[name="robots" i]'));
  if (meta.og_image) { const a = resolve(meta.og_image, base); if (a) meta.og_image = a; }
  if (meta.canonical) { const a = resolve(meta.canonical, base); if (a) meta.canonical = a; }
  const iconEl = doc.querySelector('link[rel~="icon" i], link[rel="shortcut icon" i]');
  if (iconEl) { const a = resolve(iconEl.getAttribute('href') || '', base); if (a) meta.favicon = a; }

  // Scripts: counters and chats go; in DOM mode all code goes (the page is already built), data scripts stay.
  doc.querySelectorAll('script').forEach((s) => {
    const type = (s.getAttribute('type') || '').trim().toLowerCase();
    const src = s.getAttribute('src');
    const isCode = type === '' || /(javascript|ecmascript)$/.test(type) || type === 'module' || type === 'text/babel';
    if (!isCode) {
      if (type === 'importmap' || type === 'speculationrules') { s.remove(); return; }
      if (!src) {
        // A counter hidden in a script a page switches on later (cookie consent of Tilda: type text/plain) goes as well.
        const code = s.textContent;
        for (const [label, re] of inlineTrackers) {
          if (re.test(code)) { trackers.add(label); s.remove(); return; }
        }
        s.textContent = inText(code, false);
      }
      return;
    }
    if (args.mode === 'dom' && !args.keepScripts) { s.remove(); return; }
    if (src && /\/(contact-form-7\/includes\/(js|swv)|wpcf7|wpforms\/assets\/js|ninja-forms\/assets\/js|elementor-pro\/assets\/js\/forms)/i.test(src)) {
      // The forms of these plugins ask the old site for their rules; the copy sends the form to the requests of this site.
      notes.push('скрипт формы не перенесён: ' + src.slice(0, 120));
      s.remove();
      return;
    }
    if (src) {
      const abs = resolve(src, base);
      const t = trackerOf(abs || src);
      if (t) { trackers.add(t); s.remove(); return; }
      const l = local(src, base, false);
      if (l) { s.setAttribute('src', l); s.removeAttribute('integrity'); s.removeAttribute('crossorigin'); s.removeAttribute('nonce'); return; }
      notes.push('скрипт не взят: ' + (abs || src).slice(0, 160));
      s.remove();
      return;
    }
    const code = s.textContent;
    for (const [label, re] of inlineTrackers) {
      if (re.test(code)) { trackers.add(label); s.remove(); return; }
    }
    // A widget that writes a page from a base64 string into its frame (the 2GIS reviews widget): look into the string.
    for (const m of code.matchAll(/["']([A-Za-z0-9+\/=]{200,})["']/g)) {
      let decoded = '';
      try { decoded = new TextDecoder().decode(Uint8Array.from(atob(m[1]), (c) => c.charCodeAt(0))); } catch { decoded = ''; }
      const label = trackerOf(decoded);
      if (label) { trackers.add(label); notes.push('виджет, собирающий страницу из строки, не перенесён: ' + label); s.remove(); return; }
    }
    s.textContent = inText(code, false);
    s.removeAttribute('nonce');
  });
  doc.querySelectorAll('noscript').forEach((n) => {
    const inner = n.innerHTML;
    if (trackerOf(inner) || /mc\.yandex|facebook\.com\/tr|googletagmanager|top-fwz1|vk\.com\/rtrg/i.test(inner)) { n.remove(); }
  });

  // Frames: video and map embeds are kept, everything else (chats, counters) is dropped.
  doc.querySelectorAll('iframe').forEach((f) => {
    const src = f.getAttribute('src') || f.getAttribute('data-src') || '';
    const abs = src ? resolve(src, base) : null;
    if (!abs) return;
    const t = trackerOf(abs);
    const u = new URL(abs);
    const key = u.host.toLowerCase() + u.pathname;
    const ok = args.framePrefixes.some((p) => key.startsWith(p) || (u.host.toLowerCase() + u.pathname + u.search).startsWith(p));
    if (t || !ok) {
      if (t) trackers.add(t); else notes.push('встроенный фрейм не перенесён: ' + abs.slice(0, 160));
      f.remove();
      return;
    }
    f.setAttribute(f.hasAttribute('src') ? 'src' : 'data-src', abs);
    f.removeAttribute('sandbox');
  });

  // Links in head.
  doc.querySelectorAll('link').forEach((l) => {
    const rel = (l.getAttribute('rel') || '').toLowerCase();
    if (/(preload|prefetch|prerender|dns-prefetch|preconnect|modulepreload|manifest|pingback|editURI|wlwmanifest|shortlink|canonical|alternate|amphtml|next|prev|profile|api\.w\.org|search|me|webmention)/i.test(rel) && !/stylesheet|icon/.test(rel)) { l.remove(); return; }
    const href = l.getAttribute('href');
    if (!href) return;
    const t = trackerOf(resolve(href, base) || href);
    if (t) { trackers.add(t); l.remove(); return; }
    const loc = local(href, base, true);
    if (loc) { l.setAttribute('href', loc); l.removeAttribute('integrity'); l.removeAttribute('crossorigin'); return; }
    if (/stylesheet|icon/.test(rel)) {
      const abs = resolve(href, base);
      if (abs && /^https?:/i.test(abs) && !failed.has(abs.split('#')[0])) return; // still to be fetched in the next round
      notes.push('файл не взят: ' + (abs || href).slice(0, 160));
      l.remove();
    }
  });
  doc.querySelectorAll('meta').forEach((m) => {
    const name = (m.getAttribute('name') || '').toLowerCase();
    const prop = (m.getAttribute('property') || '').toLowerCase();
    const he = (m.getAttribute('http-equiv') || '').toLowerCase();
    if (m.hasAttribute('charset') || he === 'content-type' || he === 'refresh' || he === 'content-security-policy' || name === 'description' || prop.startsWith('og:') || name.startsWith('twitter:')
      || name === 'robots' || name === 'googlebot' || name === 'yandex' || name.includes('verification') || name === 'msvalidate.01' || name === 'generator' || prop.startsWith('article:')) m.remove();
  });
  if (baseEl) baseEl.remove();
  if (titleEl) titleEl.remove();

  // Forms: a form that sent its data to another service is switched to the requests of this site.
  const forms = [];
  doc.querySelectorAll('form').forEach((f, i) => {
    const action = f.getAttribute('action') || '';
    const method = (f.getAttribute('method') || 'get').toLowerCase();
    const names = Array.from(f.querySelectorAll('input,textarea,select')).map((e) => e.getAttribute('name') || '').filter(Boolean);
    const isSearch = (/[?&/](s|q|search|query)=?/i.test(action) || f.getAttribute('role') === 'search' || names.some((n) => /^(s|q|search|query|searchword)$/i.test(n))) && method === 'get' && !f.querySelector('textarea');
    const hidden = (f.querySelector('input[type=password]') !== null);
    let kind = 'form';
    if (/tilda/i.test(action + f.className + (f.getAttribute('data-formactiontype') || ''))) kind = 'tilda';
    else if (f.classList.contains('wpcf7-form') || /wpcf7|forminator|elementor-form|gform|ninja-forms|wpforms|contact-form|jetform/i.test(f.className + action)) kind = 'wordpress';
    else if (/bitrix|b24/i.test(action + f.className)) kind = 'bitrix';
    const info = { index: i, kind, action: action.slice(0, 200), method, fields: names.slice(0, 30), search: isSearch };
    if (isSearch) {
      f.setAttribute('action', '/search');
      f.setAttribute('method', 'get');
      f.querySelectorAll('input').forEach((inp) => { if (/^(s|q|search|query|searchword)$/i.test(inp.getAttribute('name') || '')) inp.setAttribute('name', 'q'); });
      f.removeAttribute('onsubmit');
    } else if (!hidden && (f.querySelector('input:not([type=hidden]):not([type=submit]):not([type=button]), textarea') || f.querySelector('button, input[type=submit]'))) {
      f.setAttribute('data-ag-form', '1');
      f.removeAttribute('action');
      f.removeAttribute('onsubmit');
      f.setAttribute('method', 'post');
      f.removeAttribute('enctype');
      f.removeAttribute('data-formactiontype');
      info.rewired = true;
    }
    forms.push(info);
  });

  // Every element: addresses in attributes.
  const KNOWN = /^(src|href|poster|data|data-src|data-srcset|data-original|data-lazy-src|data-lazy|data-bg|data-background|data-image|data-img|data-url|data-full|data-large|data-thumb|data-poster|data-video|data-mp4|data-webm|data-img-zoom-url|data-bg-image|data-background-image|data-image-url|xlink:href|data-zoom|data-original-src)$/i;
  const SKIP_ATTR = /^(class|id|style|alt|title|value|placeholder|name|type|rel|lang|role|target|for|data-testid|content|action|method|width|height|d|points|viewbox|transform|fill|stroke)$|^(aria-|on[a-z]+)/i;
  const all = doc.querySelectorAll('*');
  for (const el of all) {
    const tag = el.tagName.toLowerCase();
    if (tag === 'a' || tag === 'area') {
      const href = el.getAttribute('href');
      if (href !== null && !skipRef(href.trim())) {
        const abs = resolve(href.trim(), base);
        if (abs && /^https?:/i.test(abs)) {
          const u = new URL(abs);
          const fileLike = FILE_EXT.test(u.pathname) && !/\.(html?|php|aspx?)$/i.test(u.pathname) && !/\.(js|css|json)$/i.test(u.pathname);
          const loc = local(abs, base, false);
          if (loc) el.setAttribute('href', loc);
          else if (bare(u.host) === siteHost) {
            if (fileLike && !/\.(zip)$/i.test(u.pathname)) {
              const l2 = local(abs, base, true);
              if (l2) el.setAttribute('href', l2);
              else if (!failed.has(abs.split('#')[0])) { /* downloaded in the next round */ } else { el.setAttribute('href', u.pathname + u.search + u.hash); }
            } else {
              el.setAttribute('href', u.pathname + u.search + u.hash);
            }
          } else if (el.getAttribute('target') === '_blank' && !el.hasAttribute('rel')) {
            el.setAttribute('rel', 'noopener noreferrer');
          }
        }
      }
    }
    for (const attr of Array.from(el.attributes)) {
      const name = attr.name;
      let v = attr.value;
      if (v === '' || v.length > 20000) continue;
      if (name === 'style') {
        if (v.indexOf('url(') >= 0) el.setAttribute('style', css(v, base));
        continue;
      }
      if (/srcset/i.test(name)) {
        if (/^(img|source)$/.test(tag) || true) {
          const out = srcset(v, base);
          if (out === '' && (tag === 'img' || tag === 'source')) el.removeAttribute(name); else el.setAttribute(name, out);
          // the candidates used by this viewport must be saved by the caller; they were requested by the browser
        }
        continue;
      }
      if (tag === 'a' && name === 'href') continue;
      if (/^on[a-z]+$/i.test(name)) {
        if (v.indexOf('//') >= 0) el.setAttribute(name, inText(v, false));
        continue;
      }
      if (KNOWN.test(name)) {
        const wantFetch = /^(src|href|poster|data)$/i.test(name) && tag !== 'a';
        if (v.indexOf('url(') >= 0) { el.setAttribute(name, css(v, base)); continue; }
        if (/^\s*[^\s'"<>]+\s*$/.test(v)) {
          const l = local(v, base, wantFetch);
          if (l) el.setAttribute(name, l);
          else if (tag === 'img' && name === 'src' && wantFetch) { /* fetched next round */ }
        } else if (v.indexOf('//') >= 0) {
          el.setAttribute(name, inText(v, false));
        }
        continue;
      }
      if (SKIP_ATTR.test(name)) {
        if (name === 'content' && /^(https?:)?\/\//.test(v)) { /* meta content: left to SEO */ }
        continue;
      }
      // data-* and others: a lone address of a saved file, a url(...) string or JSON with addresses.
      if (v.indexOf('url(') >= 0) { el.setAttribute(name, css(v, base)); continue; }
      if (/^\s*[^\s'"<>]+\s*$/.test(v) && v.length < 1500 && (/^(https?:)?\/\//.test(v) || /^\.{0,2}\/[^/]/.test(v) || /^[\w%.~@-]+(\/[\w%.~@+-]+)+\.\w{2,5}(\?.*)?$/.test(v) || /^[\w%.~-]+\.(png|jpe?g|gif|webp|svg|avif|css|js|woff2?|mp4|webm|ico)(\?.*)?$/i.test(v))) {
        const l = local(v, base, false);
        if (l) el.setAttribute(name, l);
      } else if (v.indexOf('//') >= 0 || v.indexOf('\\/\\/') >= 0) {
        const nv = inText(v, false);
        if (nv !== v) el.setAttribute(name, nv);
      }
    }
    if (tag === 'style') {
      el.textContent = css(el.textContent, base);
      el.removeAttribute('nonce');
    }
    if (tag === 'img' || tag === 'iframe') {
      if (el.getAttribute('loading') === 'lazy' && args.mode === 'dom') el.removeAttribute('loading');
    }
    el.removeAttribute('integrity');
    if (el.hasAttribute('nonce')) el.removeAttribute('nonce');
  }

  // Menus, headers and footers are left where they are (the page is a copy); only collect the visible text for search.
  const clone = body.cloneNode(true);
  clone.querySelectorAll('script,style,noscript,template,svg,iframe').forEach((n) => n.remove());
  const text = (clone.textContent || '').replace(/\s+/g, ' ').trim();

  const rootAttrs = {};
  for (const a of Array.from(html.attributes)) rootAttrs[a.name] = a.value;
  const bodyAttrs = {};
  for (const a of Array.from(body.attributes)) bodyAttrs[a.name] = a.value;

  // Links to other pages of the site (for the crawler), as written.
  const links = [];
  if (args.collectLinks) {
    doc.querySelectorAll('a[href]').forEach((a) => {
      const h = a.getAttribute('href') || '';
      if (h && !skipRef(h)) links.push(h);
    });
  }
  return {
    html: html.outerHTML, rootAttrs, bodyAttrs,
    meta, forms, notes, trackers: [...trackers], missing: [...missing], text: text.slice(0, 400000), textLength: text.length, links,
  };
}
