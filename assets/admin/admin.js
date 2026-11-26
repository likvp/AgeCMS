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

    // ---- sorting of tables by a click on a column header ------------------------------------------------------------------
    // Every table of the admin that has a header is sortable, the pages of modules included: a module writes plain markup and
    // brings no script (CSP). A click on a header: ascending, descending, the order of the server. Values are read as dates,
    // numbers (sums, sizes) or text; <td data-sort="..."> gives the value itself. Rows «Итого» and [data-sort-fixed] stay at
    // the bottom, a row of one wide cell stays under the row before it. [data-nosort] on a table or a <th> turns it off, only
    // for an order that is itself the data. A header with its own link or field (sorting on the server) is left alone.
    // The sort works on the rows of the page: a list cut into pages is sorted page by page.
    var collator = window.Intl && Intl.Collator ? new Intl.Collator('ru', { numeric: true, sensitivity: 'base' }) : null;
    function compareText(a, b) { return collator ? collator.compare(a, b) : a.localeCompare(b); }
    // The text a person sorts by: without the second line under the title, buttons and the hidden parts of <details>.
    function visibleText(node, brief) {
        var out = '';
        Array.prototype.forEach.call(node.childNodes, function (c) {
            if (c.nodeType === 3) { out += c.nodeValue; return; }
            if (c.nodeType !== 1) { return; }
            var tag = c.tagName.toUpperCase();
            if (/^(BUTTON|SELECT|TEXTAREA|NOSCRIPT|SCRIPT|STYLE|TEMPLATE|SVG|DIALOG)$/.test(tag) || c.hidden || c.classList.contains('sr')) { return; }
            if (brief && (tag === 'SMALL' || c.classList.contains('cell-sub'))) { return; }
            if (tag === 'DETAILS') { var head = c.querySelector('summary'); out += head ? ' ' + visibleText(head, brief) + ' ' : ''; return; }
            out += ' ' + visibleText(c, brief) + ' ';
        });
        return out.replace(/\s+/g, ' ').trim();
    }
    function cellText(cell) {
        if (!cell) { return ''; }
        if (cell.hasAttribute('data-sort')) { return cell.getAttribute('data-sort').trim(); }
        var text = visibleText(cell, true);
        if (text !== '') { return text; }
        var field = cell.querySelector('select, textarea, input:not([type=hidden]):not([type=submit]):not([type=button])');
        if (field) {
            if (field.type === 'checkbox' || field.type === 'radio') { return field.checked ? '1' : '0'; }
            if (field.tagName === 'SELECT') { return field.options[field.selectedIndex] ? field.options[field.selectedIndex].text.trim() : ''; }
            return field.value.trim();
        }
        return visibleText(cell, false);
    }
    // «6 декабря», «вс, 6 декабря», «26 нояб. 2026 в 14:05». Without a year it is the one that puts the day closest to today.
    var months = { 'янв': 1, 'фев': 2, 'мар': 3, 'апр': 4, 'мая': 5, 'май': 5, 'июн': 6, 'июл': 7, 'авг': 8, 'сен': 9, 'окт': 10, 'ноя': 11, 'дек': 12 };
    var wordDate = /^(?:(?:с|от|до) )?(?:(?:пн|вт|ср|чт|пт|сб|вс),? )?(\d{1,2}) (января|янв|февраля|февр|фев|марта|мар|апреля|апр|мая|май|июня|июн|июля|июл|августа|авг|сентября|сент|сен|октября|окт|ноября|нояб|ноя|декабря|дек)(?![а-яё])\.?(?: (\d{4}))?(?:(?:,? в |,? )(\d{1,2}):(\d{2}))?/i;
    function wordDateParts(t) {
        var m = wordDate.exec(t);
        if (!m) { return null; }
        var month = months[m[2].slice(0, 3).toLowerCase()], year = m[3];
        if (!year) {
            var now = new Date(), away = (month - 1 - now.getMonth()) * 30 + parseInt(m[1], 10) - now.getDate();
            year = now.getFullYear() + (away < -183 ? 1 : (away > 183 ? -1 : 0));
        }
        return [year, month, m[1], m[4], m[5], 0];
    }
    function asDate(t) {
        var m = /^(?:(?:с|от|до) )?(\d{1,2})\.(\d{1,2})\.(\d{4})(?:,? +(\d{1,2}):(\d{2})(?::(\d{2}))?)?/.exec(t);
        var p = m ? [m[3], m[2], m[1], m[4], m[5], m[6]] : null;
        if (!p) {
            m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/.exec(t);
            p = m ? [m[1], m[2], m[3], m[4], m[5], m[6]] : null;
        }
        p = p || wordDateParts(t);
        return p ? p.reduce(function (sum, v) { return sum * 100 + (parseInt(v, 10) || 0); }, 0) : null;
    }
    // «8 000 ₽», «-12,5 %», «от 300», «№ 7», «3 блока», «1,2 МБ», «4 500 ₽ дети 3 000 ₽» (the first sum). Otherwise a second
    // number in the text makes it text, not a number.
    function asNumber(t) {
        var m = /^(?:(?:от|до|около|№|#|~) ?)?([-+−]?(?:\d{1,3}(?:[    ]\d{3})+|\d+)(?:[.,]\d+)?)(?!\d)(.*)$/i.exec(t);
        if (!m || (/\d/.test(m[2]) && !/^\s*(₽|руб|р\.|%|€|\$|[кмгт]б(?![а-яё]))/i.test(m[2]))) { return null; }
        var n = parseFloat(m[1].replace(/[    ]/g, '').replace(',', '.').replace('−', '-'));
        var size = /^\s*([кмгт])(?:б|иб)(?![а-яё])/i.exec(m[2]);
        return size ? n * Math.pow(1024, 'кмгт'.indexOf(size[1].toLowerCase()) + 1) : n;
    }
    function columnCell(row, column) {
        var at = 0;
        for (var i = 0; i < row.cells.length; i++) {
            at += row.cells[i].colSpan;
            if (column < at) { return row.cells[i]; }
        }
        return null;
    }
    function isTotalRow(row) {
        return row.hasAttribute('data-sort-fixed') || /(^|[\s_-])(total|itogo)($|[\s_-])/i.test(row.className)
            || /^(итого|всего)(?![а-яё])/i.test(row.cells.length ? visibleText(row.cells[0], true) : '');
    }
    function headRowOf(table) {
        if (table.tHead && table.tHead.rows.length) { return table.tHead.rows[table.tHead.rows.length - 1]; }
        // No <thead>: the first row counts as the header when it is all <th> and data rows follow.
        var first = table.rows[0];
        var allTh = first && table.rows.length > 1 && first.cells.length > 1
            && Array.prototype.every.call(first.cells, function (c) { return c.tagName === 'TH'; })
            && Array.prototype.some.call(table.rows[1].cells, function (c) { return c.tagName === 'TD'; });
        return allTh ? first : null;
    }
    function sortBody(body, headRow, columns, state) {
        var rows = Array.prototype.filter.call(body.rows, function (r) { return r !== headRow; });
        rows.forEach(function (r) { if (r.__agPos === undefined) { r.__agPos = body.__agNext = (body.__agNext || 0) + 1; } });
        rows.sort(function (a, b) { return a.__agPos - b.__agPos; });
        var top = [], units = [], bottom = [];
        rows.forEach(function (r) {
            if (isTotalRow(r)) { bottom.push(r); return; }
            if (columns > 1 && r.cells.length === 1 && r.cells[0].colSpan > 1) {
                if (units.length) { units[units.length - 1].rows.push(r); } else { top.push(r); }
                return;
            }
            units.push({ rows: [r], pos: r.__agPos });
        });
        if (state) {
            var dates = 0, numbers = 0, filled = 0;
            units.forEach(function (u) {
                u.text = cellText(columnCell(u.rows[0], state.column));
                u.empty = u.text === '' || u.text === '-';
                u.date = u.empty ? null : asDate(u.text);
                u.number = u.empty || u.date !== null ? null : asNumber(u.text);
                if (!u.empty) { filled++; }
                if (u.date !== null) { dates++; }
                if (u.number !== null) { numbers++; }
            });
            var kind = dates && dates * 2 >= filled ? 'date' : (numbers && numbers * 2 >= filled ? 'number' : 'text');
            units.forEach(function (u) { u.last = u.empty || (kind !== 'text' && u[kind] === null); });
            var sign = state.dir === 'desc' ? -1 : 1;
            units.sort(function (a, b) {
                if (a.last || b.last) { return a.last === b.last ? a.pos - b.pos : (a.last ? 1 : -1); }
                var d = kind === 'text' ? 0 : a[kind] - b[kind];
                if (d === 0) { d = compareText(a.text, b.text); }
                return d === 0 ? a.pos - b.pos : d * sign;
            });
        }
        var order = top;
        units.forEach(function (u) { order = order.concat(u.rows); });
        order.concat(bottom).forEach(function (r) { body.appendChild(r); });
    }
    function applySort(table) {
        var s = table.__agSort;
        Array.prototype.forEach.call(table.tBodies, function (body) { sortBody(body, s.headRow, s.columns, s.state); });
        s.heads.forEach(function (h) {
            if (s.state && s.state.column === h.column) { h.th.setAttribute('aria-sort', s.state.dir === 'desc' ? 'descending' : 'ascending'); } else { h.th.removeAttribute('aria-sort'); }
        });
        if (s.select) { s.select.value = s.state ? s.state.column + ':' + s.state.dir : ''; }
    }
    function setSort(table, state) {
        var s = table.__agSort;
        s.state = state;
        try {
            if (state) { sessionStorage.setItem(s.key, state.column + ':' + state.dir); } else { sessionStorage.removeItem(s.key); }
        } catch (e) { /* ignore */ }
        applySort(table);
    }
    function parseState(raw) {
        var m = /^(\d+):(asc|desc)$/.exec(raw || '');
        return m ? { column: parseInt(m[1], 10), dir: m[2] } : null;
    }
    // A module may turn its table into cards on a phone and hide the header: then a list «Сортировать» stands above the table.
    function sortBar(table) {
        var s = table.__agSort;
        var style = window.getComputedStyle(s.headRow.parentNode);
        var hidden = style.display === 'none' || style.visibility === 'hidden' || style.position === 'absolute'
            || window.getComputedStyle(s.headRow).display === 'none';
        if (!s.bar && hidden) {
            var bar = document.createElement('label'); bar.className = 'tbl-sortbar';
            var name = document.createElement('span'); name.textContent = 'Сортировать';
            var select = document.createElement('select'); select.className = 'in sm';
            select.add(new Option('как было', ''));
            s.heads.forEach(function (h) {
                select.add(new Option(h.label + ', по возрастанию', h.column + ':asc'));
                select.add(new Option(h.label + ', по убыванию', h.column + ':desc'));
            });
            select.addEventListener('change', function (e) { e.stopPropagation(); setSort(table, parseState(select.value)); });
            bar.appendChild(name); bar.appendChild(select);
            var box = table.parentNode.classList.contains('tbl-scroll') ? table.parentNode : table;
            box.parentNode.insertBefore(bar, box);
            s.bar = bar; s.select = select;
            select.value = s.state ? s.state.column + ':' + s.state.dir : '';
        }
        if (s.bar) { s.bar.hidden = !hidden; }
    }
    function makeSortable(table, index) {
        if (table.__agSort) { applySort(table); return; }
        if (table.closest('[data-nosort], [contenteditable]') || table.querySelector('tbody [rowspan]:not([rowspan="1"])')) { return; }
        var headRow = headRowOf(table);
        if (!headRow) { return; }
        var heads = [], columns = 0;
        Array.prototype.forEach.call(headRow.cells, function (th) {
            var column = columns;
            columns += th.colSpan;
            var label = th.textContent.replace(/\s+/g, ' ').trim();
            if (th.tagName !== 'TH' || label === '' || th.hasAttribute('data-nosort') || th.querySelector('a, button, input, select, label')) { return; }
            var button = document.createElement('button');
            button.type = 'button'; button.className = 'th-sort';
            while (th.firstChild) { button.appendChild(th.firstChild); }
            th.appendChild(button);
            th.classList.add('th-able');
            th.addEventListener('click', function () {
                var now = table.__agSort.state;
                var same = now && now.column === column;
                setSort(table, !same ? { column: column, dir: 'asc' } : (now.dir === 'asc' ? { column: column, dir: 'desc' } : null));
            });
            heads.push({ th: th, column: column, label: label });
        });
        if (!heads.length) { return; }
        // The choice lives as long as the browser tab: a page reloaded after saving a form keeps it.
        var key = 'ag-sort:' + location.pathname + location.search + ':' + index + ':' + heads.map(function (h) { return h.label; }).join('|');
        var saved = null;
        try { saved = parseState(sessionStorage.getItem(key)); } catch (e) { /* ignore */ }
        if (saved && !heads.some(function (h) { return h.column === saved.column; })) { saved = null; }
        table.__agSort = { headRow: headRow, columns: columns, heads: heads, key: key, state: saved, bar: null, select: null };
        applySort(table);
        sortBar(table);
    }
    function sortTables(root) {
        var found = Array.prototype.slice.call(root.querySelectorAll('table'));
        var around = root.closest ? root.closest('table') : null;
        if (around) { found.push(around); }
        var all = Array.prototype.slice.call(document.querySelectorAll('table'));
        found.forEach(function (t) { makeSortable(t, all.indexOf(t)); });
    }
    window.agSortTables = sortTables;
    window.addEventListener('resize', function () {
        document.querySelectorAll('table').forEach(function (t) { if (t.__agSort) { sortBar(t); } });
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
        sortTables(root);
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
