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
}());
