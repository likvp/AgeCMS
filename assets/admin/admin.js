(function () {
    'use strict';

    // ---- theme control ----------------------------------------------------------------------
    var seg = document.querySelectorAll('[data-theme-set]');
    function currentTheme() {
        var t = document.documentElement.getAttribute('data-theme');
        return t === 'light' || t === 'dark' ? t : 'auto';
    }
    function paintTheme() {
        var cur = currentTheme();
        seg.forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-theme-set') === cur ? 'true' : 'false'); });
    }
    seg.forEach(function (b) {
        b.addEventListener('click', function () {
            var t = b.getAttribute('data-theme-set');
            if (t === 'auto') { document.documentElement.removeAttribute('data-theme'); } else { document.documentElement.setAttribute('data-theme', t); }
            try { if (t === 'auto') { localStorage.removeItem('ag-theme'); } else { localStorage.setItem('ag-theme', t); } } catch (e) { /* ignore */ }
            paintTheme();
        });
    });
    paintTheme();

    // ---- toasts -----------------------------------------------------------------------------
    var toasts = document.getElementById('toasts');
    window.agToast = function (text, kind) {
        if (!toasts) { return; }
        var t = document.createElement('div');
        t.className = 'toast' + (kind === 'error' ? ' error' : '');
        t.textContent = text;
        toasts.appendChild(t);
        setTimeout(function () { t.remove(); }, kind === 'error' ? 7000 : 3500);
    };

    // ---- confirm dialog for [data-confirm] forms --------------------------------------------
    var dlg;
    function confirmDialog(title, text, okLabel, danger) {
        return new Promise(function (resolve) {
            if (!dlg) {
                dlg = document.createElement('dialog');
                document.body.appendChild(dlg);
            }
            dlg.textContent = '';
            var h = document.createElement('h2'); h.textContent = title;
            var p = document.createElement('p'); p.textContent = text || '';
            var row = document.createElement('div'); row.className = 'row';
            var no = document.createElement('button'); no.type = 'button'; no.className = 'btn ghost'; no.textContent = 'Отмена';
            var ok = document.createElement('button'); ok.type = 'button'; ok.className = 'btn ' + (danger ? 'danger' : 'primary'); ok.textContent = okLabel || 'Подтвердить';
            row.appendChild(no); row.appendChild(ok);
            dlg.appendChild(h); if (text) { dlg.appendChild(p); } dlg.appendChild(row);
            var done = function (v) { dlg.close(); resolve(v); };
            no.onclick = function () { done(false); };
            ok.onclick = function () { done(true); };
            dlg.oncancel = function () { resolve(false); };
            dlg.showModal();
            no.focus();
        });
    }
    window.agConfirm = confirmDialog;
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var msg = form.getAttribute && form.getAttribute('data-confirm');
        if (!msg || form.__confirmed) { return; }
        e.preventDefault();
        confirmDialog(msg, form.getAttribute('data-confirm-text') || '', form.getAttribute('data-confirm-ok') || 'Подтвердить', form.hasAttribute('data-danger')).then(function (yes) {
            if (yes) { form.__confirmed = true; form.submit(); }
        });
    });

    // ---- live filter of a table ------------------------------------------------------------
    document.querySelectorAll('[data-filter]').forEach(function (input) {
        var rows = document.querySelectorAll(input.getAttribute('data-filter') + ' tbody tr');
        var none = document.getElementById('filter-none');
        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase(), shown = 0;
            rows.forEach(function (r) {
                var hit = q === '' || r.textContent.toLowerCase().indexOf(q) !== -1;
                r.hidden = !hit;
                if (hit) { shown++; }
            });
            if (none) { none.hidden = shown !== 0; }
        });
    });

    // ---- copy to clipboard -----------------------------------------------------------------
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-copy]') : null;
        if (!b) { return; }
        var src = document.querySelector(b.getAttribute('data-copy'));
        if (!src || !navigator.clipboard) { return; }
        navigator.clipboard.writeText(src.textContent.trim()).then(function () { window.agToast('Скопировано'); });
    });

    // ---- password field switch in the installer --------------------------------------------
    document.querySelectorAll('[data-pw-mode]').forEach(function (radio) {
        var target = document.getElementById('pw-own');
        function sync() {
            var own = document.querySelector('[data-pw-mode]:checked');
            if (target && own) { target.hidden = own.value !== 'own'; var inp = target.querySelector('input'); if (inp) { inp.required = own.value === 'own'; } }
        }
        radio.addEventListener('change', sync);
        sync();
    });

    // ---- page address made from the title (same rules as core/Slug.php) ----------------------
    var slugForm = document.querySelector('[data-autoslug]');
    if (slugForm) {
        var map = {'а':'a','б':'b','в':'v','г':'g','д':'d','е':'e','ё':'e','ж':'zh','з':'z','и':'i','й':'y','к':'k','л':'l','м':'m','н':'n','о':'o','п':'p','р':'r','с':'s','т':'t','у':'u','ф':'f','х':'kh','ц':'ts','ч':'ch','ш':'sh','щ':'shch','ъ':'','ы':'y','ь':'','э':'e','ю':'yu','я':'ya'};
        var titleIn = slugForm.querySelector('[data-slug-from]');
        var slugIn = slugForm.querySelector('[data-slug]');
        var parentIn = slugForm.querySelector('[data-slug-parent]');
        var touched = false;
        var makeSlug = function (t) {
            var s = t.toLowerCase().replace(/[а-яё]/g, function (c) { return map[c]; }).replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
            if (s.length > 60) { s = s.slice(0, 60); var cut = s.lastIndexOf('-'); s = (cut > 20 ? s.slice(0, cut) : s).replace(/-+$/g, ''); }
            return s;
        };
        var fill = function () {
            if (touched) { return; }
            var base = makeSlug(titleIn.value);
            slugIn.value = base === '' ? '' : (parentIn && parentIn.value && parentIn.value !== 'index' ? parentIn.value + '/' : '') + base;
        };
        slugIn.addEventListener('input', function () { touched = slugIn.value !== ''; });
        titleIn.addEventListener('input', fill);
        if (parentIn) { parentIn.addEventListener('change', fill); }
    }

    // ---- auto-submit selects (role switch) -------------------------------------------------
    document.querySelectorAll('[data-autosubmit]').forEach(function (sel) {
        sel.addEventListener('change', function () { if (sel.form.requestSubmit) { sel.form.requestSubmit(); } else { sel.form.submit(); } });
    });

    // ---- media page: drag and drop upload --------------------------------------------------
    var drop = document.querySelector('[data-upload]');
    var wrap = document.querySelector('[data-csrf]');
    if (drop && wrap && window.fetch && window.FormData) {
        drop.classList.remove('nojs');
        var input = drop.querySelector('input[type=file]');
        var busy = false;
        var sendAll = function (files) {
            files = Array.prototype.filter.call(files, function (f) { return /^image\//.test(f.type); });
            if (!files.length || busy) { if (!files.length) { window.agToast('Нужны картинки: JPEG, PNG, GIF или WebP', 'error'); } return; }
            busy = true;
            var ok = 0, failed = 0, i = 0;
            var bar = document.createElement('progress');
            bar.max = files.length; bar.value = 0;
            drop.appendChild(bar);
            var next = function () {
                if (i >= files.length) {
                    busy = false;
                    bar.remove();
                    if (ok > 0) { setTimeout(function () { location.href = '/admin/?s=media&up=' + ok; }, failed ? 2500 : 200); }
                    return;
                }
                var fd = new FormData();
                fd.append('file', files[i]);
                fetch('/admin/upload.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': wrap.getAttribute('data-csrf') }, body: fd })
                    .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Сервер вернул не JSON, код ' + r.status }; }); })
                    .then(function (d) {
                        if (d.ok) { ok++; } else { failed++; window.agToast(files[i].name + ': ' + (d.error || 'не загружено'), 'error'); }
                    })
                    .catch(function () { failed++; window.agToast('Нет связи с сервером', 'error'); })
                    .then(function () { i++; bar.value = i; next(); });
            };
            next();
        };
        input.addEventListener('change', function () { sendAll(input.files); input.value = ''; });
        ['dragenter', 'dragover'].forEach(function (n) { drop.addEventListener(n, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
        ['dragleave', 'drop'].forEach(function (n) { drop.addEventListener(n, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
        drop.addEventListener('drop', function (e) { sendAll(e.dataTransfer.files); });
        drop.addEventListener('submit', function (e) { e.preventDefault(); });
    }

    // ---- live parts of module pages: [data-ag-poll] (GET) and [data-ag-step] (POST with the CSRF token) ---------------
    // Pages of modules cannot bring their own scripts (CSP), so the core does it for them. The element asks an address of a
    // module page (same site, /admin/m/...) again and again and shows the answer:
    //  - an HTML fragment (the core sends just the body of the module page when the request carries X-AgeCMS-Poll) replaces
    //    the content of the element; a [data-ag-done] element inside it stops the loop, its value "reload" reloads the page,
    //    an /admin/... address opens that address;
    //  - JSON {done, html, fields, location, reload}: html replaces the content, fields fill [data-ag-field="name"] elements
    //    (text, or value of progress/meter/input; data-ag-max="name" takes max from another field), done stops the loop.
    // The fragment is inserted as HTML: it is what the module page would print anyway, and inline scripts or handlers in it do
    // not run (CSP has no 'unsafe-inline'), so nothing gets more rights than the page itself. Forms in it keep working
    // (confirm dialogs are delegated). data-ag-step does the same with POST and the CSRF token of the admin, so a module can
    // do its work in portions while the page is open, without cron.
    function liveUrl(raw) {
        if (!raw || !window.URL) { return null; }
        try {
            var u = new URL(raw, location.href);
            return u.origin === location.origin && u.pathname.indexOf('/admin/m/') === 0 ? u : null;
        } catch (e) { return null; }
    }
    function adminUrl(raw) {
        try {
            var u = new URL(raw, location.href);
            return u.origin === location.origin && u.pathname.indexOf('/admin/') === 0 ? u : null;
        } catch (e) { return null; }
    }
    function csrfFor(el) {
        var own = el.getAttribute('data-ag-csrf');
        if (own) { return own; }
        var field = (el.closest && el.closest('form') ? el.closest('form').querySelector('input[name="csrf"]') : null) || document.querySelector('input[name="csrf"]');
        if (field) { return field.value; }
        var wrapEl = document.querySelector('[data-csrf]');
        return wrapEl ? wrapEl.getAttribute('data-csrf') : '';
    }
    function fillFields(el, fields) {
        el.querySelectorAll('[data-ag-field]').forEach(function (node) {
            var name = node.getAttribute('data-ag-field');
            if (!Object.prototype.hasOwnProperty.call(fields, name)) { return; }
            var v = fields[name];
            var maxName = node.getAttribute('data-ag-max');
            if (maxName && Object.prototype.hasOwnProperty.call(fields, maxName)) { node.max = Math.max(1, Number(fields[maxName]) || 1); }
            if (/^(PROGRESS|METER|INPUT|SELECT|TEXTAREA)$/.test(node.tagName)) { node.value = v; } else { node.textContent = v === null || v === undefined ? '' : String(v); }
        });
    }
    function startLive(el) {
        if (el.__agLive || !window.fetch) { return; }
        var post = el.hasAttribute('data-ag-step');
        var url = liveUrl(el.getAttribute(post ? 'data-ag-step' : 'data-ag-poll'));
        if (!url) { return; }
        el.__agLive = true;
        var every = parseInt(el.getAttribute(post ? 'data-ag-step-interval' : 'data-ag-poll-interval') || el.getAttribute('data-ag-poll-interval') || '', 10);
        every = Math.min(600000, Math.max(post ? 200 : 1000, isNaN(every) ? 2000 : every));
        if (!el.hasAttribute('aria-live')) { el.setAttribute('aria-live', 'polite'); }
        var fails = 0;
        var finish = function (how) {
            el.removeAttribute('aria-busy');
            var to = how === 'reload' ? null : (how ? adminUrl(how) : null);
            if (how === 'reload') { location.reload(); } else if (to) { location.href = to.href; }
        };
        var later = function (ms) { setTimeout(tick, ms); };
        var tick = function () {
            if (!document.body.contains(el)) { return; }
            if (!post && document.hidden) { later(every); return; }
            var opt = { method: post ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', headers: { 'X-AgeCMS-Poll': '1', 'Accept': 'application/json, text/html' } };
            if (post) {
                var fd = new FormData();
                fd.append('csrf', csrfFor(el));
                opt.body = fd;
            }
            el.setAttribute('aria-busy', 'true');
            fetch(url.href, opt).then(function (r) {
                var type = r.headers.get('Content-Type') || '';
                if (type.indexOf('application/json') !== -1) {
                    return r.json().then(function (d) { return { r: r, json: d }; });
                }
                return r.text().then(function (t) { return { r: r, text: t }; });
            }).then(function (res) {
                el.removeAttribute('aria-busy');
                var r = res.r;
                if (r.status === 429 || r.status >= 502) { throw new Error('busy'); }
                fails = 0;
                if (res.json) {
                    var d = res.json || {};
                    if (typeof d.html === 'string') { el.innerHTML = d.html; initLive(el); }
                    if (d.fields && typeof d.fields === 'object') { fillFields(el, d.fields); }
                    if (d.done || !r.ok) { finish(d.reload ? 'reload' : (d.location || '')); return; }
                    later(every);
                    return;
                }
                if (r.headers.get('X-AgeCMS-Fragment') !== '1') {
                    // Not a fragment of the module: the session ended or the address went away. The page itself says what.
                    location.reload();
                    return;
                }
                el.innerHTML = res.text;
                initLive(el);
                var done = el.querySelector('[data-ag-done]');
                if (done || !r.ok) { finish(done ? done.getAttribute('data-ag-done') : ''); return; }
                later(every);
            }).catch(function () {
                el.removeAttribute('aria-busy');
                fails++;
                if (fails >= 6) { window.agToast('Нет связи с сервером: обновите страницу', 'error'); return; }
                later(every * Math.min(8, Math.pow(2, fails)));
            });
        };
        later(every);
    }
    function initLive(root) {
        root.querySelectorAll('[data-ag-poll], [data-ag-step]').forEach(startLive);
    }
    window.agLive = initLive;
    initLive(document);

    // Tabs of a long page: on a phone the row scrolls sideways, the current tab is brought into view.
    document.querySelectorAll('.tabs').forEach(function (nav) {
        var on = nav.querySelector('[aria-current="page"]');
        if (on && nav.scrollWidth > nav.clientWidth) { nav.scrollLeft = on.offsetLeft - (nav.clientWidth - on.offsetWidth) / 2; }
    });
}());
