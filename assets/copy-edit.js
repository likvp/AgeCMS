/*
 * Text editor of a copied page. Loaded only for a signed-in person who may write pages and has switched editing on.
 * The server marks every editable element with data-ag-e="N" (CopyEdit::mark). A click on one makes its own text editable in place;
 * a link gets an address field, a picture can be replaced by a file. "Сохранить" sends only what changed, and the server writes it
 * into the stored markup (modules/importer/src/Admin/TextEdit.php). Nothing but text, addresses and picture files can change.
 */
(function () {
  'use strict';
  var me = document.currentScript;
  if (!me) return;
  var cfg = { slug: me.dataset.slug || '', variant: me.dataset.variant || '', base: me.dataset.base || '', csrf: me.dataset.csrf || '', exit: me.dataset.exit || '/' };
  var BASE = '/admin/m/importer/copy-edit';
  var UPLOAD = '/admin/upload.php';
  var state = {}; // n -> {orig: [..], href, src, alt, dirty}
  var current = null;
  var bar, status, saveBtn, undoBtn, linkRow, linkInput, imgRow, altInput, pickBtn, fileInput;

  function norm(s) { return String(s).replace(/[\s ]+/g, ' ').trim(); }
  function textNodes(el) {
    var out = [];
    for (var c = el.firstChild; c; c = c.nextSibling) {
      if (c.nodeType === 3 && norm(c.nodeValue) !== '') out.push(c);
    }
    return out;
  }
  function textsOf(el) { return textNodes(el).map(function (n) { return n.nodeValue; }); }
  function numberOf(el) { return Number(el.getAttribute('data-ag-e')); }
  function inBar(t) { return bar && bar.contains(t); }
  function say(text, bad) { status.textContent = text || ''; status.className = 'ag-ce-status' + (bad ? ' err' : ''); status.hidden = !text; }

  function snapshot(el) {
    var n = numberOf(el);
    if (!state[n]) {
      state[n] = { orig: textsOf(el), href: el.tagName === 'A' ? el.getAttribute('href') || '' : null, src: el.tagName === 'IMG' ? el.getAttribute('src') || '' : null, alt: el.tagName === 'IMG' ? el.getAttribute('alt') || '' : null, el: el, dirty: false };
    }
    return state[n];
  }
  function changed(n) {
    var s = state[n];
    if (!s) return false;
    var el = s.el;
    var now = textsOf(el);
    var textChanged = now.length === s.orig.length && now.some(function (t, i) { return t !== s.orig[i]; });
    var hrefChanged = s.href !== null && (el.getAttribute('href') || '') !== s.href;
    var srcChanged = s.src !== null && (el.getAttribute('src') || '') !== s.src;
    var altChanged = s.alt !== null && (el.getAttribute('alt') || '') !== s.alt;
    return textChanged || hrefChanged || srcChanged || altChanged;
  }
  function refresh() {
    var count = 0;
    Object.keys(state).forEach(function (n) {
      var d = changed(n);
      state[n].el.classList.toggle('ag-ce-dirty', d);
      if (d) count++;
    });
    saveBtn.disabled = count === 0;
    undoBtn.disabled = count === 0;
    saveBtn.textContent = count ? 'Сохранить (' + count + ')' : 'Сохранить';
    return count;
  }

  function stopEditing() {
    if (current) {
      current.removeAttribute('contenteditable');
      current.classList.remove('ag-ce-on');
      current = null;
    }
  }
  function startEditing(el, x, y) {
    if (current === el) return;
    stopEditing();
    var s = snapshot(el);
    linkRow.hidden = true;
    imgRow.hidden = true;
    var link = el.tagName === 'A' ? el : (el.closest ? el.closest('a[data-ag-e]') : null);
    if (link) {
      snapshot(link);
      linkInput.value = link.getAttribute('href') || '';
      linkInput.dataset.n = String(numberOf(link));
      linkRow.hidden = false;
    }
    if (el.tagName === 'IMG') {
      altInput.value = el.getAttribute('alt') || '';
      imgRow.dataset.n = String(numberOf(el));
      imgRow.hidden = false;
      current = el;
      el.classList.add('ag-ce-on');
      return;
    }
    if (s.orig.length === 0) return; // a link around a picture: only its address
    current = el;
    el.classList.add('ag-ce-on');
    el.setAttribute('contenteditable', 'true');
    el.focus();
    try {
      var r = document.caretRangeFromPoint ? document.caretRangeFromPoint(x, y) : null;
      if (r && el.contains(r.startContainer)) { var sel = getSelection(); sel.removeAllRanges(); sel.addRange(r); }
    } catch (e) { /* the caret stays where the browser put it */ }
  }

  function onClick(e) {
    if (inBar(e.target) || e.target === me) return;
    var el = e.target.closest ? e.target.closest('[data-ag-e]') : null;
    // A link or a button around the text must not be followed while editing.
    var a = e.target.closest ? e.target.closest('a,button,[onclick]') : null;
    if (el || a) { e.preventDefault(); e.stopPropagation(); }
    if (el) startEditing(el, e.clientX, e.clientY);
    else stopEditing();
  }
  function swallow(e) {
    if (inBar(e.target)) return;
    var t = e.target.closest ? e.target.closest('[data-ag-e]') : null;
    if (t && t !== current && (e.type === 'submit' || e.type === 'mousedown' || e.type === 'dblclick')) {
      if (e.type !== 'mousedown') { e.preventDefault(); e.stopPropagation(); }
    }
  }
  function onKey(e) {
    if (!current || current.tagName === 'IMG') return;
    if (e.key === 'Enter') { e.preventDefault(); return; } // a new line would add markup the server does not take
    if (e.key === 'Escape') { stopEditing(); return; }
    // The keys the page's own scripts (sliders, menus) listen to must not fire while typing.
    e.stopPropagation();
  }
  function onPaste(e) {
    if (!current) return;
    e.preventDefault();
    var t = (e.clipboardData || window.clipboardData).getData('text/plain').replace(/[\r\n]+/g, ' ');
    document.execCommand('insertText', false, t);
  }
  function onDrop(e) { if (current && !inBar(e.target)) e.preventDefault(); }

  function collect() {
    var edits = [];
    var problems = [];
    Object.keys(state).forEach(function (n) {
      if (!changed(n)) return;
      var s = state[n];
      var el = s.el;
      var edit = { n: Number(n) };
      var now = textsOf(el);
      if (now.length !== s.orig.length) { problems.push(n); return; }
      if (now.some(function (t, i) { return t !== s.orig[i]; })) { edit.from = s.orig; edit.to = now; }
      if (s.href !== null && (el.getAttribute('href') || '') !== s.href) edit.href = el.getAttribute('href') || '';
      if (s.src !== null && (el.getAttribute('src') || '') !== s.src) edit.src = el.getAttribute('src') || '';
      if (s.alt !== null && (el.getAttribute('alt') || '') !== s.alt) edit.alt = el.getAttribute('alt') || '';
      edits.push(edit);
    });
    return { edits: edits, problems: problems };
  }

  function post(url, form) {
    return fetch(url, { method: 'POST', body: form, credentials: 'same-origin', headers: { 'X-CSRF-Token': cfg.csrf } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Ответ сервера не разобран (код ' + r.status + ').' }; }); });
  }

  function save() {
    stopEditing();
    var c = collect();
    if (c.problems.length) {
      say('В блоках ' + c.problems.join(', ') + ' удалён или разбит текст, такое здесь не сохраняется. Нажмите «Отменить правки» или правьте код страницы.', true);
      return;
    }
    if (!c.edits.length) { say('Изменений нет.'); return; }
    saveBtn.disabled = true;
    say('Сохраняю...');
    var form = new FormData();
    form.append('csrf', cfg.csrf);
    form.append('payload', JSON.stringify({ slug: cfg.slug, variant: cfg.variant, base: cfg.base, edits: c.edits }));
    post(BASE, form).then(function (j) {
      if (!j.ok) { say(j.error || 'Не сохранилось.', true); refresh(); return; }
      var r = j.result;
      cfg.base = r.base;
      var skipped = {};
      r.skipped.forEach(function (s) { skipped[s.n] = s.why; });
      c.edits.forEach(function (e) {
        if (skipped[e.n]) return;
        var s = state[e.n];
        s.orig = textsOf(s.el);
        if (s.href !== null) s.href = s.el.getAttribute('href') || '';
        if (s.src !== null) s.src = s.el.getAttribute('src') || '';
        if (s.alt !== null) s.alt = s.el.getAttribute('alt') || '';
      });
      var bad = Object.keys(skipped);
      say('Сохранено: ' + r.applied + (bad.length ? '. Не сохранено ' + bad.length + ': ' + skipped[bad[0]] : '. Прежняя версия лежит в истории страницы.'), bad.length > 0);
      refresh();
    }).catch(function () { say('Нет связи с сайтом.', true); refresh(); });
  }

  function undoAll() {
    stopEditing();
    Object.keys(state).forEach(function (n) {
      var s = state[n];
      var nodes = textNodes(s.el);
      if (nodes.length === s.orig.length) nodes.forEach(function (t, i) { t.nodeValue = s.orig[i]; });
      if (s.href !== null) s.el.setAttribute('href', s.href);
      if (s.src !== null) s.el.setAttribute('src', s.src);
      if (s.alt !== null) s.el.setAttribute('alt', s.alt);
    });
    say('Правки отменены.');
    refresh();
  }

  function setLink() {
    var n = Number(linkInput.dataset.n);
    var s = state[n];
    if (!s) return;
    s.el.setAttribute('href', linkInput.value.trim());
    refresh();
  }
  function setAlt() {
    var n = Number(imgRow.dataset.n);
    var s = state[n];
    if (!s) return;
    s.el.setAttribute('alt', altInput.value);
    refresh();
  }
  function pickImage() { fileInput.value = ''; fileInput.click(); }
  function uploadImage() {
    var f = fileInput.files && fileInput.files[0];
    var n = Number(imgRow.dataset.n);
    var s = state[n];
    if (!f || !s) return;
    say('Загружаю картинку...');
    var form = new FormData();
    form.append('file', f);
    post(UPLOAD, form).then(function (j) {
      if (!j.ok) { say(j.error || 'Картинка не загрузилась.', true); return; }
      var p = j.result.path;
      if (p && p.charAt(0) !== '/') p = '/' + p;
      ['srcset', 'sizes', 'data-src', 'data-srcset', 'data-original', 'data-lazy-src'].forEach(function (a) { s.el.removeAttribute(a); });
      s.el.setAttribute('src', p);
      say('Картинка загружена. Нажмите «Сохранить».');
      refresh();
    }).catch(function () { say('Нет связи с сайтом.', true); });
  }

  function hot(e) {
    var t = e.target.closest ? e.target.closest('[data-ag-e]') : null;
    document.querySelectorAll('.ag-ce-hot').forEach(function (x) { x.classList.remove('ag-ce-hot'); });
    if (t && !inBar(t)) t.classList.add('ag-ce-hot');
  }

  function build() {
    bar = document.createElement('div');
    bar.className = 'ag-ce-bar';
    bar.innerHTML = '<b>Правка текста</b><span class="ag-ce-tip">Нажмите на текст, ссылку или картинку на странице и измените. Enter и вставка форматирования отключены.</span>'
      + '<label class="ag-ce-link" hidden>Адрес ссылки <input type="text" maxlength="2000" placeholder="https://..."></label>'
      + '<span class="ag-ce-img" hidden><label>Описание картинки <input type="text" maxlength="500" class="ag-ce-alt"></label> <button type="button" class="ag-ce-pick">Заменить картинку...</button></span>'
      + '<button type="button" class="primary ag-ce-save" disabled>Сохранить</button><button type="button" class="ag-ce-undo" disabled>Отменить правки</button><a class="ag-ce-exit" href="">Выйти из правки</a>'
      + '<span class="ag-ce-status" hidden></span><input type="file" accept="image/*" hidden>';
    document.body.appendChild(bar);
    status = bar.querySelector('.ag-ce-status');
    saveBtn = bar.querySelector('.ag-ce-save');
    undoBtn = bar.querySelector('.ag-ce-undo');
    linkRow = bar.querySelector('.ag-ce-link');
    linkInput = linkRow.querySelector('input');
    imgRow = bar.querySelector('.ag-ce-img');
    altInput = bar.querySelector('.ag-ce-alt');
    pickBtn = bar.querySelector('.ag-ce-pick');
    fileInput = bar.querySelector('input[type=file]');
    bar.querySelector('.ag-ce-exit').href = cfg.exit;
    saveBtn.addEventListener('click', save);
    undoBtn.addEventListener('click', undoAll);
    linkInput.addEventListener('input', setLink);
    altInput.addEventListener('input', setAlt);
    pickBtn.addEventListener('click', pickImage);
    fileInput.addEventListener('change', uploadImage);
  }

  function init() {
    build();
    document.addEventListener('click', onClick, true);
    ['submit', 'dblclick'].forEach(function (t) { document.addEventListener(t, swallow, true); });
    document.addEventListener('keydown', onKey, true);
    document.addEventListener('paste', onPaste, true);
    document.addEventListener('drop', onDrop, true);
    document.addEventListener('input', function (e) { if (e.target === current) refresh(); }, true);
    document.addEventListener('mouseover', hot, true);
    window.addEventListener('beforeunload', function (e) { if (refresh() > 0) { e.preventDefault(); e.returnValue = ''; } });
    window.agCopyEdit = { collect: collect, save: save };
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
