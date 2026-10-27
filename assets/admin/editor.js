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
                        el('b', { text: '#' + (i + 1) }),
                        iconBtn('caret-up', 'Выше', function () { move(list, i, -1, draw); }, { disabled: i === 0 }),
                        iconBtn('caret-down', 'Ниже', function () { move(list, i, 1, draw); }, { disabled: i === list.length - 1 }),
                        iconBtn('trash', 'Удалить пункт', function () { list.splice(i, 1); setDirty(); draw(); }, { danger: true })
                    ])
                ].concat(fieldsOf(spec.of, item))));
            });
            add.disabled = list.length >= max;
        }
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
                field({ type: 'string', max: 500 }, page, 'og_image', 'Картинка для соцсетей (адрес)')
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

        root.textContent = '';
        root.appendChild(el('div', { class: 'editor' }, [
            el('div', {}, [el('div', { class: 'savebar' }, [saveBtn, status.node]), blocksBody, addRow]),
            el('aside', { class: 'aside' }, [main, seo])
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
                            iconBtn('caret-up', 'Выше', function () { move(items, i, -1, draw); }, { disabled: i === 0 }),
                            iconBtn('caret-down', 'Ниже', function () { move(items, i, 1, draw); }, { disabled: i === items.length - 1 }),
                            iconBtn('trash', 'Удалить пункт', function () { items.splice(i, 1); setDirty(); draw(); }, { danger: true })
                        ])
                    ]));
                });
                addBtn.disabled = items.length >= 30;
            }

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
