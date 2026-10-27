(function () {
    'use strict';

    var dataEl = document.getElementById('ag-editor-data');
    var root = document.getElementById('ag-editor');
    if (!dataEl || !root) { return; }
    var data = JSON.parse(dataEl.textContent);
    var dirty = false;
    var statusEl;

    function el(tag, attrs, kids) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'class') { node.className = attrs[k]; }
            else if (k === 'text') { node.textContent = attrs[k]; }
            else if (k.indexOf('on') === 0) { node.addEventListener(k.slice(2), attrs[k]); }
            else if (attrs[k] === true) { node.setAttribute(k, ''); }
            else if (attrs[k] !== false && attrs[k] != null) { node.setAttribute(k, attrs[k]); }
        });
        (kids || []).forEach(function (kid) { if (kid) { node.appendChild(kid); } });
        return node;
    }

    function markDirty() {
        dirty = true;
        say('Есть несохранённые изменения', '');
    }

    function say(text, kind) {
        if (statusEl) {
            statusEl.textContent = text;
            statusEl.className = 'ag-status' + (kind ? ' ' + kind : '');
        }
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
        }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Сервер вернул не JSON, код ' + r.status }; }); });
    }

    function button(text, onclick, cls, extra) {
        return el('button', Object.assign({ type: 'button', class: 'btn small ' + (cls || ''), text: text, onclick: onclick }, extra || {}));
    }

    function move(list, i, delta, rerender) {
        var j = i + delta;
        if (j < 0 || j >= list.length) { return; }
        var item = list.splice(i, 1)[0];
        list.splice(j, 0, item);
        markDirty();
        rerender();
    }

    // ---- field controls built from the theme schema -------------------------------------------

    function setValue(obj, key, spec, value) {
        if (value === '' && !spec.required) { delete obj[key]; } else { obj[key] = value; }
        markDirty();
    }

    function field(spec, obj, key, label) {
        var type = spec.type || 'string';
        var title = label + (spec.required ? ' *' : '');
        var control;
        if (type === 'list') { return listField(spec, obj, key, title); }
        if (type === 'text') {
            control = el('textarea', { rows: 4, maxlength: spec.max || 5000, oninput: function (e) { setValue(obj, key, spec, e.target.value); } });
            control.value = obj[key] || '';
        } else if (type === 'bool') {
            control = el('input', { type: 'checkbox', onchange: function (e) { obj[key] = e.target.checked; markDirty(); } });
            control.checked = obj[key] === true;
            return el('label', { class: 'ag-check' }, [control, el('span', { text: ' ' + title })]);
        } else if (type === 'int') {
            control = el('input', { type: 'number', min: spec.min, max: spec.max, onchange: function (e) {
                if (e.target.value === '') { delete obj[key]; } else { obj[key] = parseInt(e.target.value, 10); }
                markDirty();
            } });
            control.value = obj[key] === undefined ? '' : obj[key];
        } else if (type === 'enum') {
            control = el('select', { onchange: function (e) { setValue(obj, key, spec, e.target.value); } },
                (spec.required ? [] : [el('option', { value: '', text: '-' })]).concat(spec.values.map(function (v) { return el('option', { value: v, text: v }); })));
            control.value = obj[key] || (spec.required ? spec.values[0] : '');
            if (spec.required && obj[key] === undefined) { obj[key] = spec.values[0]; }
        } else {
            control = el('input', { type: 'text', maxlength: spec.max || 200, oninput: function (e) { setValue(obj, key, spec, e.target.value); } });
            control.value = obj[key] || '';
        }
        return el('label', { class: 'ag-field' }, [el('span', { class: 'ag-label', text: title }), control]);
    }

    function fieldsOf(specs, obj) {
        return Object.keys(specs).map(function (name) { return field(specs[name], obj, name, name); });
    }

    function listField(spec, obj, key, title) {
        if (!Array.isArray(obj[key])) { obj[key] = []; }
        var list = obj[key];
        var box = el('fieldset', { class: 'ag-list' }, [el('legend', { text: title + ' (до ' + (spec.max || 50) + ')' })]);
        var body = el('div');
        box.appendChild(body);
        function draw() {
            body.textContent = '';
            list.forEach(function (item, i) {
                body.appendChild(el('div', { class: 'ag-item' }, [
                    el('div', { class: 'ag-item-head' }, [
                        el('strong', { text: '#' + (i + 1) }),
                        button('вверх', function () { move(list, i, -1, draw); }, 'ghost', { disabled: i === 0 }),
                        button('вниз', function () { move(list, i, 1, draw); }, 'ghost', { disabled: i === list.length - 1 }),
                        button('удалить', function () { list.splice(i, 1); markDirty(); draw(); }, 'ghost danger')
                    ])
                ].concat(fieldsOf(spec.of, item))));
            });
            add.disabled = list.length >= (spec.max || 50);
        }
        var add = button('Добавить пункт', function () { list.push({}); markDirty(); draw(); }, '');
        box.appendChild(add);
        draw();
        return box;
    }

    // ---- page editor --------------------------------------------------------------------------

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
        var metaSpecs = [
            ['title', { type: 'string', max: 200, required: true }, 'Заголовок страницы'],
            ['meta_title', { type: 'string', max: 200 }, 'Title для поисковиков'],
            ['meta_description', { type: 'text', max: 500 }, 'Description для поисковиков'],
            ['canonical_url', { type: 'string', max: 500 }, 'Canonical (только если нужен другой адрес)'],
            ['og_image', { type: 'string', max: 500 }, 'Картинка для соцсетей (адрес)'],
            ['sort_order', { type: 'int', min: -100000, max: 100000 }, 'Порядок в списке']
        ];
        var meta = el('section', { class: 'ag-card' }, [el('h2', { text: 'Параметры страницы' })]);
        metaSpecs.forEach(function (m) { meta.appendChild(field(m[1], page, m[0], m[2])); });
        meta.appendChild(el('div', { class: 'ag-grid' }, [
            field({ type: 'enum', values: data.schemaTypes, required: true }, page, 'schema_type', 'Тип страницы (schema.org)'),
            parentField()
        ]));
        meta.appendChild(field({ type: 'bool' }, flagProxy('is_published'), 'v', 'Опубликована'));
        meta.appendChild(field({ type: 'bool' }, flagProxy('is_noindex'), 'v', 'Скрыть от поисковиков (noindex)'));

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
            var select = el('select', { onchange: function (e) { page.parent_slug = e.target.value; markDirty(); } },
                [el('option', { value: '', text: 'без родителя' })].concat(data.pages.map(function (p) {
                    return el('option', { value: p.slug, text: p.title + ' (/' + p.slug + ')' });
                })));
            select.value = page.parent_slug || '';
            return el('label', { class: 'ag-field' }, [el('span', { class: 'ag-label', text: 'Родитель' }), select]);
        }

        var blocksBox = el('section', { class: 'ag-card' }, [el('h2', { text: 'Блоки' })]);
        var blocksBody = el('div');
        blocksBox.appendChild(blocksBody);

        function drawBlocks() {
            blocksBody.textContent = '';
            if (page.blocks.length === 0) {
                blocksBody.appendChild(el('p', { text: 'Блоков пока нет. Добавьте первый ниже.' }));
            }
            page.blocks.forEach(function (block, i) {
                var spec = data.blockTypes[block.type] || { title: block.type, fields: {} };
                var card = el('div', { class: 'ag-block' }, [
                    el('div', { class: 'ag-item-head' }, [
                        el('strong', { text: (i + 1) + '. ' + (spec.title || block.type) }),
                        el('code', { text: block.id }),
                        button('вверх', function () { move(page.blocks, i, -1, drawBlocks); }, 'ghost', { disabled: i === 0 }),
                        button('вниз', function () { move(page.blocks, i, 1, drawBlocks); }, 'ghost', { disabled: i === page.blocks.length - 1 }),
                        button('удалить', function () {
                            if (confirm('Удалить блок «' + (spec.title || block.type) + '»? Вернуть можно из истории.')) {
                                page.blocks.splice(i, 1); markDirty(); drawBlocks();
                            }
                        }, 'ghost danger')
                    ])
                ].concat(fieldsOf(spec.fields || {}, block.data)));
                blocksBody.appendChild(card);
            });
        }

        var typeSelect = el('select', {}, Object.keys(data.blockTypes).map(function (t) {
            return el('option', { value: t, text: data.blockTypes[t].title || t });
        }));
        blocksBox.appendChild(el('div', { class: 'row' }, [
            typeSelect,
            button('Добавить блок', function () {
                var t = typeSelect.value;
                page.blocks.push({ id: newBlockId(t, page.blocks), type: t, data: {} });
                markDirty();
                drawBlocks();
            }, '')
        ]));

        statusEl = el('span', { class: 'ag-status', role: 'status' });
        var saveBtn = button('Сохранить', function () {
            var payload = {};
            Object.keys(page).forEach(function (k) { if (k !== 'theme') { payload[k] = page[k]; } });
            // A cleared optional field must overwrite the stored value, so it is sent as an empty one.
            ['title', 'parent_slug', 'meta_title', 'meta_description', 'canonical_url', 'og_image'].forEach(function (k) { if (payload[k] === undefined) { payload[k] = ''; } });
            if (payload.sort_order === undefined) { payload.sort_order = 0; }
            saveBtn.disabled = true;
            say('Сохранение...', '');
            post({ action: 'update_page', slug: data.slug, fields: payload }).then(function (d) {
                saveBtn.disabled = false;
                if (d.ok) {
                    dirty = false;
                    say(d.result && d.result.changed ? 'Сохранено, ревизия #' + d.result.revision_id : 'Без изменений', 'ok');
                } else {
                    say(d.error || 'Не удалось сохранить', 'error');
                }
            }).catch(function () { saveBtn.disabled = false; say('Нет связи с сервером', 'error'); });
        }, 'primary');

        root.appendChild(meta);
        root.appendChild(blocksBox);
        root.appendChild(el('div', { class: 'ag-savebar' }, [saveBtn, statusEl]));
        drawBlocks();
    }

    // ---- menu editor --------------------------------------------------------------------------

    function menuEditor() {
        var names = { header: 'Верхнее меню', footer: 'Нижнее меню' };
        Object.keys(names).forEach(function (name) {
            var items = (data.menus[name] || []).map(function (i) { return { title: i.title, url: i.url }; });
            var card = el('section', { class: 'ag-card' }, [el('h2', { text: names[name] })]);
            var body = el('div');
            var status = el('span', { class: 'ag-status', role: 'status' });
            var datalist = el('datalist', { id: 'ag-known-' + name }, data.known.map(function (k) {
                return el('option', { value: k.url, label: k.title });
            }));

            function draw() {
                body.textContent = '';
                items.forEach(function (item, i) {
                    var title = el('input', { type: 'text', maxlength: 60, placeholder: 'Название', oninput: function (e) { item.title = e.target.value; markDirty(); } });
                    var url = el('input', { type: 'text', maxlength: 300, placeholder: '/адрес', list: 'ag-known-' + name, oninput: function (e) { item.url = e.target.value; markDirty(); } });
                    title.value = item.title;
                    url.value = item.url;
                    body.appendChild(el('div', { class: 'ag-menu-row' }, [
                        title, url,
                        button('вверх', function () { move(items, i, -1, draw); }, 'ghost', { disabled: i === 0 }),
                        button('вниз', function () { move(items, i, 1, draw); }, 'ghost', { disabled: i === items.length - 1 }),
                        button('удалить', function () { items.splice(i, 1); markDirty(); draw(); }, 'ghost danger')
                    ]));
                });
                addBtn.disabled = items.length >= 30;
            }

            var addBtn = button('Добавить пункт', function () { items.push({ title: '', url: '/' }); markDirty(); draw(); }, '');
            var saveBtn = button('Сохранить', function () {
                saveBtn.disabled = true;
                status.textContent = 'Сохранение...';
                status.className = 'ag-status';
                post({ action: 'set_menu', name: name, items: items }).then(function (d) {
                    saveBtn.disabled = false;
                    if (d.ok) {
                        dirty = false;
                        status.textContent = d.result && d.result.changed ? 'Сохранено, ревизия #' + d.result.revision_id : 'Без изменений';
                        status.className = 'ag-status ok';
                    } else {
                        status.textContent = d.error || 'Не удалось сохранить';
                        status.className = 'ag-status error';
                    }
                }).catch(function () { saveBtn.disabled = false; status.textContent = 'Нет связи с сервером'; status.className = 'ag-status error'; });
            }, 'primary');

            card.appendChild(datalist);
            card.appendChild(body);
            card.appendChild(el('div', { class: 'row' }, [addBtn, saveBtn, status]));
            root.appendChild(card);
            draw();
        });
    }

    if (data.mode === 'page') { pageEditor(); } else if (data.mode === 'menus') { menuEditor(); }
}());
