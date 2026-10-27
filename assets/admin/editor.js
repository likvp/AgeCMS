(function () {
    'use strict';

    var dataEl = document.getElementById('ag-editor-data');
    var root = document.getElementById('ag-editor');
    if (!dataEl || !root) { return; }
    var data = JSON.parse(dataEl.textContent);
    var dirty = false;
    var markers = [];

    // ---- tiny DOM helpers -------------------------------------------------------------------

    function el(tag, attrs, kids) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            var v = attrs[k];
            if (k === 'class') { node.className = v; }
            else if (k === 'text') { node.textContent = v; }
            else if (k.indexOf('on') === 0) { node.addEventListener(k.slice(2), v); }
            else if (v === true) { node.setAttribute(k, ''); }
            else if (v !== false && v != null) { node.setAttribute(k, v); }
        });
        (kids || []).forEach(function (kid) { if (kid) { node.appendChild(kid); } });
        return node;
    }

    function ico(name) {
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('class', 'i');
        svg.setAttribute('aria-hidden', 'true');
        var use = document.createElementNS(ns, 'use');
        use.setAttribute('href', '/assets/admin/icons.svg#' + name);
        svg.appendChild(use);
        return svg;
    }

    function iconBtn(name, label, onclick, extra) {
        var b = el('button', Object.assign({ type: 'button', class: 'ib' + (extra && extra.danger ? ' danger' : ''), title: label, 'aria-label': label, onclick: onclick }, extra && extra.disabled ? { disabled: true } : {}), [ico(name)]);
        return b;
    }

    function gripBtn() {
        return el('button', { type: 'button', class: 'ib grip', title: 'Перетащить', 'aria-label': 'Перетащить' }, [ico('dots-six-vertical')]);
    }

    // Pointer based reordering: grab a .grip, drag the item, drop. Works with mouse, touch and pen.
    // The up/down buttons stay for keyboard users. onDrop(from, to) updates the data and redraws.
    function sortable(container, itemSel, onDrop, redraw) {
        container.addEventListener('mousedown', function (e) { if (e.target.closest('.grip')) { e.preventDefault(); } });
        container.addEventListener('pointerdown', function (e) {
            var handle = e.target.closest('.grip');
            if (!handle || (e.pointerType === 'mouse' && e.button !== 0)) { return; }
            var item = handle.closest(itemSel);
            if (!item || item.parentNode !== container) { return; }
            e.preventDefault();
            var items = function () { return Array.prototype.filter.call(container.children, function (c) { return c.matches(itemSel); }); };
            var from = items().indexOf(item);
            var lastY = e.clientY;
            var raf = 0;
            item.classList.add('drag');
            document.body.classList.add('dragging');

            function place() {
                var before = null;
                var sibs = items().filter(function (x) { return x !== item; });
                for (var i = 0; i < sibs.length; i++) {
                    var r = sibs[i].getBoundingClientRect();
                    if (lastY < r.top + r.height / 2) { before = sibs[i]; break; }
                }
                if (before) { if (item.nextElementSibling !== before) { container.insertBefore(item, before); } }
                else if (container.lastElementChild !== item) { container.appendChild(item); }
            }
            function tick() {
                var vh = window.innerHeight;
                if (lastY < 70) { window.scrollBy(0, -14); } else if (lastY > vh - 70) { window.scrollBy(0, 14); } else { raf = 0; return; }
                place();
                raf = requestAnimationFrame(tick);
            }
            function move(ev) { lastY = ev.clientY; place(); if (!raf) { raf = requestAnimationFrame(tick); } }
            function end(cancelled) {
                document.removeEventListener('pointermove', move);
                document.removeEventListener('pointerup', up);
                document.removeEventListener('pointercancel', cancel);
                if (raf) { cancelAnimationFrame(raf); }
                item.classList.remove('drag');
                document.body.classList.remove('dragging');
                var to = items().indexOf(item);
                if (to === from) { return; }
                if (cancelled) { redraw(); } else { onDrop(from, to); }
            }
            function up() { end(false); }
            function cancel() { end(true); }
            // Listen on the document: moving the item in the DOM releases the pointer capture of the handle.
            document.addEventListener('pointermove', move);
            document.addEventListener('pointerup', up);
            document.addEventListener('pointercancel', cancel);
        });
    }

    function reorder(list, from, to) {
        var moved = list.splice(from, 1)[0];
        list.splice(to, 0, moved);
        setDirty();
    }

    // ---- media picker -----------------------------------------------------------------------

    var pickerDlg, pickerGrid, pickerPick, pickerFile, pickerMsg;

    function uploadImage(file) {
        var fd = new FormData();
        fd.append('file', file);
        return fetch('/admin/upload.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': data.csrf }, body: fd })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Сервер вернул не JSON, код ' + r.status }; }); });
    }

    function drawPicker(current) {
        pickerGrid.textContent = '';
        if (!data.media.length) {
            pickerGrid.appendChild(el('div', { class: 'empty' }, [ico('image'), el('b', { text: 'Картинок пока нет' }), el('span', { text: data.canUpload ? 'Загрузите первую кнопкой выше.' : 'Загрузка недоступна: на сервере нет GD с WebP.' })]));
            return;
        }
        var grid = el('div', { class: 'pick-grid' });
        data.media.forEach(function (url) {
            var b = el('button', { type: 'button', class: 'pick' + (url === current ? ' on' : ''), title: url, onclick: function () { pickerDlg.close(); pickerPick(url); } }, [el('img', { src: url, alt: '', loading: 'lazy' })]);
            grid.appendChild(b);
        });
        pickerGrid.appendChild(grid);
    }

    function openPicker(current, onPick) {
        if (!pickerDlg) {
            pickerGrid = el('div', { class: 'picker-b' });
            pickerMsg = el('span', { class: 'muted' });
            pickerFile = el('input', { type: 'file', accept: 'image/jpeg,image/png,image/gif,image/webp', class: 'sr', 'aria-label': 'Файл картинки', onchange: function () {
                var f = pickerFile.files[0];
                if (!f) { return; }
                pickerMsg.textContent = 'Загрузка...';
                uploadImage(f).then(function (d) {
                    pickerFile.value = '';
                    if (d.ok) {
                        pickerMsg.textContent = '';
                        data.media.unshift(d.result.path);
                        pickerDlg.close();
                        pickerPick(d.result.path);
                    } else {
                        pickerMsg.textContent = '';
                        toast(d.error || 'Не удалось загрузить', 'error');
                    }
                }).catch(function () { pickerMsg.textContent = ''; toast('Нет связи с сервером', 'error'); });
            } });
            pickerDlg = el('dialog', { class: 'picker', 'aria-label': 'Медиатека' }, [
                el('div', { class: 'picker-h' }, [
                    el('h2', { text: 'Медиатека' }), pickerMsg,
                    data.canUpload ? el('label', { class: 'btn sm primary', for: '' }, []) : null,
                    iconBtn('x-circle', 'Закрыть', function () { pickerDlg.close(); })
                ]),
                pickerGrid
            ]);
            if (data.canUpload) {
                var label = pickerDlg.querySelector('label');
                var id = 'ag-pick-file';
                pickerFile.id = id;
                label.setAttribute('for', id);
                label.appendChild(ico('upload-simple'));
                label.appendChild(document.createTextNode('Загрузить'));
                pickerDlg.querySelector('.picker-h').appendChild(pickerFile);
            }
            document.body.appendChild(pickerDlg);
        }
        pickerPick = onPick;
        drawPicker(current);
        pickerDlg.showModal();
    }

    function imageField(spec, obj, key, title) {
        var input = el('input', { class: 'in', type: 'text', maxlength: spec.max || 500, placeholder: '/assets/uploads/...', oninput: function (e) { setValue(obj, key, spec, e.target.value); preview(); } });
        input.value = obj[key] || '';
        var pv = el('img', { class: 'pv', alt: '' });
        function preview() {
            var v = input.value.trim();
            if (/^(\/|https?:\/\/)/.test(v)) { pv.src = v; pv.hidden = false; } else { pv.hidden = true; pv.removeAttribute('src'); }
        }
        var pick = iconBtn('image', 'Выбрать из медиатеки', function () {
            openPicker(input.value, function (url) { input.value = url; setValue(obj, key, spec, url); preview(); });
        });
        preview();
        return el('div', { class: 'f' }, [el('span', { text: title }), el('div', { class: 'imgf' }, [pv, input, pick])]);
    }

    function textBtn(label, onclick, cls, icon) {
        return el('button', { type: 'button', class: 'btn ' + (cls || ''), onclick: onclick }, [icon ? ico(icon) : null, document.createTextNode(label)]);
    }

    function toast(text, kind) { if (window.agToast) { window.agToast(text, kind); } }

    function setDirty() {
        dirty = true;
        markers.forEach(function (m) { m('dirty'); });
    }

    window.addEventListener('beforeunload', function (e) {
        if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    function post(payload) {
        return fetch('/admin/api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': data.csrf },
            body: JSON.stringify(payload)
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, error: 'Сервер вернул не JSON, код ' + r.status }; });
        });
    }

    // Status line with an icon: kind is '', 'dirty', 'ok' or 'error'.
    function statusLine() {
        var span = el('span', { class: 'st', role: 'status' });
        function say(text, kind) {
            span.className = 'st' + (kind ? ' ' + kind : '');
            span.textContent = '';
            if (text) {
                span.appendChild(ico(kind === 'ok' ? 'check-circle' : kind === 'error' ? 'warning-circle' : 'info'));
                span.appendChild(document.createTextNode(text));
            }
        }
        return { node: span, say: say };
    }

    function move(list, i, delta, rerender) {
        var j = i + delta;
        if (j < 0 || j >= list.length) { return; }
        var item = list.splice(i, 1)[0];
        list.splice(j, 0, item);
        setDirty();
        rerender();
    }

    // ---- field controls built from the theme schema -----------------------------------------

    function setValue(obj, key, spec, value) {
        if (value === '' && !spec.required) { delete obj[key]; } else { obj[key] = value; }
        setDirty();
    }

    function field(spec, obj, key, label) {
        var type = spec.type || 'string';
        var title = label + (spec.required ? ' *' : '');
        var control;
        if (type === 'list') { return listField(spec, obj, key, title); }
        if (spec.media && (type === 'url' || type === 'string')) { return imageField(spec, obj, key, title); }
        if (type === 'bool') {
            control = el('input', { type: 'checkbox', onchange: function (e) { obj[key] = e.target.checked; setDirty(); } });
            control.checked = obj[key] === true;
            return el('label', { class: 'chk' }, [control, el('span', { text: title })]);
        }
        if (type === 'text') {
            control = el('textarea', { class: 'in', rows: 4, maxlength: spec.max || 5000, oninput: function (e) { setValue(obj, key, spec, e.target.value); } });
            control.value = obj[key] || '';
        } else if (type === 'int') {
            control = el('input', { class: 'in', type: 'number', min: spec.min, max: spec.max, onchange: function (e) {
                if (e.target.value === '') { delete obj[key]; } else { obj[key] = parseInt(e.target.value, 10); }
                setDirty();
            } });
            control.value = obj[key] === undefined ? '' : obj[key];
        } else if (type === 'enum') {
            control = el('select', { class: 'in', onchange: function (e) { setValue(obj, key, spec, e.target.value); } },
                (spec.required ? [] : [el('option', { value: '', text: '-' })]).concat(spec.values.map(function (v) { return el('option', { value: v, text: v }); })));
            control.value = obj[key] || (spec.required ? spec.values[0] : '');
            if (spec.required && obj[key] === undefined) { obj[key] = spec.values[0]; }
        } else {
            control = el('input', { class: 'in', type: 'text', maxlength: spec.max || 200, oninput: function (e) { setValue(obj, key, spec, e.target.value); } });
            control.value = obj[key] || '';
        }
        return el('label', { class: 'f' }, [el('span', { text: title }), control]);
    }

    function fieldsOf(specs, obj) {
        return Object.keys(specs).map(function (name) { return field(specs[name], obj, name, name); });
    }

    function listField(spec, obj, key, title) {
        if (!Array.isArray(obj[key])) { obj[key] = []; }
        var list = obj[key];
        var max = spec.max || 50;
        var body = el('div');
        var add = textBtn('Добавить пункт', function () { list.push({}); setDirty(); draw(); }, 'sm', 'plus');
        var box = el('div', { class: 'lst' }, [el('span', { class: 'lbl', text: title + ' (до ' + max + ')' }), body, add]);
        function draw() {
            body.textContent = '';
            list.forEach(function (item, i) {
                body.appendChild(el('div', { class: 'itm' }, [
                    el('div', { class: 'itm-h' }, [
                        gripBtn(),
                        el('b', { text: '#' + (i + 1) }),
                        iconBtn('caret-up', 'Выше', function () { move(list, i, -1, draw); }, { disabled: i === 0 }),
                        iconBtn('caret-down', 'Ниже', function () { move(list, i, 1, draw); }, { disabled: i === list.length - 1 }),
                        iconBtn('trash', 'Удалить пункт', function () { list.splice(i, 1); setDirty(); draw(); }, { danger: true })
                    ])
                ].concat(fieldsOf(spec.of, item))));
            });
            add.disabled = list.length >= max;
        }
        sortable(body, '.itm', function (from, to) { reorder(list, from, to); draw(); }, draw);
        draw();
        return box;
    }

    // ---- page editor ------------------------------------------------------------------------

    function newBlockId(type, blocks) {
        var used = {};
        blocks.forEach(function (b) { used[b.id] = true; });
        for (var n = 1; n < 1000; n++) {
            if (!used[type + '_' + n]) { return type + '_' + n; }
        }
        return type + '_' + Date.now();
    }

    function pageEditor() {
        var page = data.page;
        if (!Array.isArray(page.blocks)) { page.blocks = []; }

        function flagProxy(name) {
            var proxy = {};
            Object.defineProperty(proxy, 'v', {
                get: function () { return page[name] === 1; },
                set: function (v) { page[name] = v ? 1 : 0; },
                enumerable: true
            });
            return proxy;
        }

        function parentField() {
            var select = el('select', { class: 'in', onchange: function (e) { page.parent_slug = e.target.value; setDirty(); } },
                [el('option', { value: '', text: 'без родителя' })].concat(data.pages.map(function (p) {
                    return el('option', { value: p.slug, text: p.title + ' (/' + p.slug + ')' });
                })));
            select.value = page.parent_slug || '';
            return el('label', { class: 'f' }, [el('span', { text: 'Родитель' }), select]);
        }

        // right column: page parameters and SEO
        var main = el('section', { class: 'panel' }, [
            el('div', { class: 'panel-h' }, [el('h2', { text: 'Страница' })]),
            el('div', { class: 'panel-b' }, [
                field({ type: 'string', max: 200, required: true }, page, 'title', 'Заголовок'),
                field({ type: 'enum', values: data.schemaTypes, required: true }, page, 'schema_type', 'Тип (schema.org)'),
                parentField(),
                field({ type: 'bool' }, flagProxy('is_published'), 'v', 'Опубликована'),
                field({ type: 'bool' }, flagProxy('is_noindex'), 'v', 'Скрыть от поисковиков (noindex)'),
                field({ type: 'int', min: -100000, max: 100000 }, page, 'sort_order', 'Порядок в списке')
            ])
        ]);
        var seo = el('section', { class: 'panel' }, [
            el('div', { class: 'panel-h' }, [el('h2', { text: 'SEO и соцсети' })]),
            el('div', { class: 'panel-b' }, [
                field({ type: 'string', max: 200 }, page, 'meta_title', 'Title'),
                field({ type: 'text', max: 500 }, page, 'meta_description', 'Description'),
                field({ type: 'string', max: 500 }, page, 'canonical_url', 'Canonical, если нужен другой адрес'),
                field({ type: 'string', max: 500, media: true }, page, 'og_image', 'Картинка для соцсетей')
            ])
        ]);

        // left column: blocks
        var blocksBody = el('div');
        var typeSelect = el('select', { class: 'in', 'aria-label': 'Тип нового блока' }, Object.keys(data.blockTypes).map(function (t) {
            return el('option', { value: t, text: data.blockTypes[t].title || t });
        }));
        var addRow = el('div', { class: 'add' }, [
            typeSelect,
            textBtn('Добавить блок', function () {
                var t = typeSelect.value;
                var id = newBlockId(t, page.blocks);
                page.blocks.push({ id: id, type: t, data: {} });
                setDirty();
                drawBlocks();
                var last = blocksBody.lastElementChild;
                if (last) { last.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
            }, '', 'plus')
        ]);
        var shut = {};

        function drawBlocks() {
            blocksBody.textContent = '';
            if (page.blocks.length === 0) {
                blocksBody.appendChild(el('div', { class: 'empty panel' }, [ico('file-text'), el('b', { text: 'Блоков пока нет' }), el('span', { text: 'Добавьте первый блок ниже.' })]));
                return;
            }
            page.blocks.forEach(function (block, i) {
                var spec = data.blockTypes[block.type] || { title: block.type, fields: {} };
                if (!block.data) { block.data = {}; }
                var title = spec.title || block.type;
                var card = el('div', { class: 'blk' + (shut[block.id] ? ' shut' : '') });
                var toggle = iconBtn('caret-down', 'Свернуть или развернуть', function () {
                    shut[block.id] = !shut[block.id];
                    card.classList.toggle('shut', !!shut[block.id]);
                });
                toggle.classList.add('tg');
                card.appendChild(el('div', { class: 'blk-h' }, [
                    gripBtn(),
                    toggle,
                    el('span', { class: 'blk-n', text: String(i + 1) }),
                    el('span', { class: 'blk-t' }, [document.createTextNode(title), el('code', { text: block.id })]),
                    iconBtn('caret-up', 'Поднять блок', function () { move(page.blocks, i, -1, drawBlocks); }, { disabled: i === 0 }),
                    iconBtn('caret-down', 'Опустить блок', function () { move(page.blocks, i, 1, drawBlocks); }, { disabled: i === page.blocks.length - 1 }),
                    iconBtn('trash', 'Удалить блок', function () {
                        window.agConfirm('Удалить блок «' + title + '»?', 'Он пропадёт после сохранения. Вернуть можно из истории.', 'Удалить', true).then(function (yes) {
                            if (yes) { page.blocks.splice(i, 1); setDirty(); drawBlocks(); }
                        });
                    }, { danger: true })
                ]));
                card.appendChild(el('div', { class: 'blk-b' }, fieldsOf(spec.fields || {}, block.data)));
                blocksBody.appendChild(card);
            });
        }

        var status = statusLine();
        var saveBtn = textBtn('Сохранить', save, 'primary', 'check-circle');
        markers.push(function () { status.say('Есть несохранённые изменения', 'dirty'); });

        function save() {
            if (saveBtn.disabled) { return; }
            var payload = {};
            Object.keys(page).forEach(function (k) { if (k !== 'theme') { payload[k] = page[k]; } });
            // A cleared optional field must overwrite the stored value, so it is sent as an empty one.
            ['title', 'parent_slug', 'meta_title', 'meta_description', 'canonical_url', 'og_image'].forEach(function (k) { if (payload[k] === undefined) { payload[k] = ''; } });
            if (payload.sort_order === undefined) { payload.sort_order = 0; }
            saveBtn.disabled = true;
            status.say('Сохранение...', '');
            post({ action: 'update_page', slug: data.slug, fields: payload }).then(function (d) {
                saveBtn.disabled = false;
                if (d.ok) {
                    dirty = false;
                    var msg = d.result && d.result.changed ? 'Сохранено, ревизия #' + d.result.revision_id : 'Без изменений';
                    status.say(msg, 'ok');
                    toast(msg);
                } else {
                    status.say(d.error || 'Не удалось сохранить', 'error');
                    toast(d.error || 'Не удалось сохранить', 'error');
                }
            }).catch(function () {
                saveBtn.disabled = false;
                status.say('Нет связи с сервером', 'error');
                toast('Нет связи с сервером', 'error');
            });
        }

        document.addEventListener('keydown', function (e) {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(); }
        });

        sortable(blocksBody, '.blk', function (from, to) { reorder(page.blocks, from, to); drawBlocks(); }, drawBlocks);

        var aside = el('aside', { class: 'aside' }, [main, seo]);
        var delTpl = document.getElementById('ag-delete-form');
        if (delTpl) { aside.appendChild(el('div', { class: 'del-zone' }, [delTpl.content.cloneNode(true)])); }

        root.textContent = '';
        root.appendChild(el('div', { class: 'editor' }, [
            el('div', {}, [el('div', { class: 'savebar' }, [saveBtn, status.node]), blocksBody, addRow]),
            aside
        ]));
        drawBlocks();
    }

    // ---- menu editor ------------------------------------------------------------------------

    function menuEditor() {
        var names = { header: 'Верхнее меню', footer: 'Нижнее меню' };
        root.textContent = '';
        Object.keys(names).forEach(function (name) {
            var items = (data.menus[name] || []).map(function (i) { return { title: i.title, url: i.url }; });
            var body = el('div', { class: 'panel-b' });
            var status = statusLine();
            markers.push(function () { status.say('Есть несохранённые изменения', 'dirty'); });
            var listId = 'ag-known-' + name;
            var datalist = el('datalist', { id: listId }, data.known.map(function (k) {
                return el('option', { value: k.url, label: k.title });
            }));

            function draw() {
                body.textContent = '';
                if (items.length === 0) {
                    body.appendChild(el('div', { class: 'empty' }, [ico('list'), el('b', { text: 'Пунктов нет' }), el('span', { text: 'Добавьте первый пункт.' })]));
                }
                items.forEach(function (item, i) {
                    var title = el('input', { class: 'in', type: 'text', maxlength: 60, placeholder: 'Название', 'aria-label': 'Название пункта', oninput: function (e) { item.title = e.target.value; setDirty(); } });
                    var url = el('input', { class: 'in u', type: 'text', maxlength: 300, placeholder: '/адрес', 'aria-label': 'Ссылка', list: listId, oninput: function (e) { item.url = e.target.value; setDirty(); } });
                    title.value = item.title;
                    url.value = item.url;
                    body.appendChild(el('div', { class: 'mrow' }, [
                        title, url,
                        el('div', { class: 'actions' }, [
                            gripBtn(),
                            iconBtn('caret-up', 'Выше', function () { move(items, i, -1, draw); }, { disabled: i === 0 }),
                            iconBtn('caret-down', 'Ниже', function () { move(items, i, 1, draw); }, { disabled: i === items.length - 1 }),
                            iconBtn('trash', 'Удалить пункт', function () { items.splice(i, 1); setDirty(); draw(); }, { danger: true })
                        ])
                    ]));
                });
                addBtn.disabled = items.length >= 30;
            }

            sortable(body, '.mrow', function (from, to) { reorder(items, from, to); draw(); }, draw);
            var addBtn = textBtn('Добавить пункт', function () { items.push({ title: '', url: '/' }); setDirty(); draw(); }, 'sm', 'plus');
            var saveBtn = textBtn('Сохранить', function () {
                saveBtn.disabled = true;
                status.say('Сохранение...', '');
                post({ action: 'set_menu', name: name, items: items }).then(function (d) {
                    saveBtn.disabled = false;
                    if (d.ok) {
                        dirty = false;
                        var msg = d.result && d.result.changed ? 'Сохранено, ревизия #' + d.result.revision_id : 'Без изменений';
                        status.say(msg, 'ok');
                        toast(names[name] + ': ' + msg.toLowerCase());
                    } else {
                        status.say(d.error || 'Не удалось сохранить', 'error');
                        toast(d.error || 'Не удалось сохранить', 'error');
                    }
                }).catch(function () { saveBtn.disabled = false; status.say('Нет связи с сервером', 'error'); });
            }, 'primary sm');

            root.appendChild(el('section', { class: 'panel' }, [
                datalist,
                el('div', { class: 'panel-h' }, [el('h2', { text: names[name] }), status.node, addBtn, saveBtn]),
                body
            ]));
            draw();
        });
    }

    if (data.mode === 'page') { pageEditor(); } else if (data.mode === 'menus') { menuEditor(); }
}());
