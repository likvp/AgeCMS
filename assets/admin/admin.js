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
}());
