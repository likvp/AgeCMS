// Page editor of AgeCMS: a signed-in editor opens a page with ?edit and works with the mouse.
//  - text: click and type, it saves by itself (elements with data-ag-field);
//  - blocks: hover a block to get its panel (drag, up, down, change, copy, delete) and "add block" strips between blocks;
//  - the "change" form is built from the block schema the page carries (#ag-editor-data), so every field, list item and picture
//    of every block can be edited even where the theme has no inline fields;
//  - every step is a revision, "Undo" and "Redo" at the bottom walk through them.
// The interface lives in a shadow root, the theme's styles cannot touch it.
(function () {
    'use strict';

    const metaCsrf = (document.querySelector('meta[name="ag-csrf"]') || {}).content || '';
    let editorData = null;
    try {
        const node = document.getElementById('ag-editor-data');
        editorData = node ? JSON.parse(node.textContent) : null;
    } catch (e) { editorData = null; }
    const csrf = metaCsrf || (editorData && editorData.csrf) || '';
    const slug = editorData ? editorData.slug : '';
    const statusEl = document.getElementById('ag-status');

    // ---------------------------------------------------------------- helpers

    function h(tag, props, kids) {
        const el = document.createElement(tag);
        Object.keys(props || {}).forEach(function (k) {
            const v = props[k];
            if (k === 'class') { el.className = v; }
            else if (k === 'text') { el.textContent = v; }
            else if (k.slice(0, 2) === 'on') { el.addEventListener(k.slice(2), v); }
            else if (v === true) { el.setAttribute(k, ''); }
            else if (v !== false && v != null) { el.setAttribute(k, v); }
        });
        (kids || []).forEach(function (kid) { if (kid) { el.appendChild(typeof kid === 'string' ? document.createTextNode(kid) : kid); } });
        return el;
    }

    const ICONS = {
        grip: '<circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/>',
        up: '<path d="M6 14l6-6 6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>',
        down: '<path d="M6 10l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>',
        edit: '<path d="M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/>',
        copy: '<rect x="8" y="8" width="11" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="1.9"/><path d="M5 15V6a1 1 0 011-1h9" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>',
        trash: '<path d="M5 7h14M10 7V5h4v2M7 7l1 12h8l1-12M10 11v5M14 11v5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>',
        plus: '<path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>',
        close: '<path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>',
        undo: '<path d="M9 7L4 12l5 5M4 12h10a6 6 0 010 12" transform="translate(0 -3)" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        redo: '<path d="M15 7l5 5-5 5M20 12H10a6 6 0 000 12" transform="translate(0 -3)" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        chevron: '<path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>',
        image: '<rect x="4" y="5" width="16" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.9"/><circle cx="9" cy="10" r="1.6"/><path d="M5 17l5-4 3 3 3-2 3 3" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/>'
    };

    function icon(name) {
        const wrap = document.createElement('span');
        wrap.className = 'ic';
        wrap.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">' + ICONS[name] + '</svg>';
        return wrap;
    }

    function say(text) {
        if (statusEl) { statusEl.textContent = text; }
    }

    /** Server messages are English and technical; the editor shows plain Russian. */
    function friendly(message) {
        const m = String(message || '');
        if (/must not be empty|is required/.test(m)) { return 'Заполните обязательные поля.'; }
        const tooLong = m.match(/longer than (\d+)/);
        if (tooLong) { return 'Слишком длинный текст, можно не больше ' + tooLong[1] + ' знаков.'; }
        if (/must be an http/.test(m)) { return 'Ссылка записана неверно: нужен адрес вида https://..., /страница, #якорь, mailto: или tel:.'; }
        if (/Too many blocks/.test(m)) { return 'На странице слишком много блоков.'; }
        if (/too many items/.test(m)) { return 'Слишком много элементов в списке.'; }
        if (/Writes are frozen/.test(m)) { return 'Правки временно закрыты, обратитесь к администратору сайта.'; }
        if (/Bad CSRF|Authentication/.test(m)) { return 'Сессия закончилась. Обновите страницу и войдите заново.'; }
        if (/Rate limit/.test(m)) { return 'Слишком часто. Подождите минуту.'; }
        return m || 'Не получилось сохранить.';
    }

    async function api(action, args) {
        let response;
        try {
            response = await fetch('/admin/api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                credentials: 'same-origin',
                body: JSON.stringify(Object.assign({ action: action }, args || {}))
            });
        } catch (e) {
            throw new Error('Нет связи с сервером');
        }
        let body;
        try { body = await response.json(); } catch (e) { throw new Error('Сервер ответил не так, как ожидалось'); }
        if (!body.ok) { throw new Error(friendly(body.error)); }
        return body.result;
    }

    async function upload(file) {
        const form = new FormData();
        form.append('file', file);
        let response;
        try {
            response = await fetch('/admin/upload.php', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin', body: form });
        } catch (e) {
            throw new Error('Нет связи с сервером');
        }
        let body;
        try { body = await response.json(); } catch (e) { throw new Error('Картинка не загрузилась'); }
        if (!body.ok) { throw new Error(body.error || 'Картинка не загрузилась'); }
        return body.result.path;
    }

    function setPath(target, path, value) {
        const keys = path.split('.');
        let node = target;
        for (let i = 0; i < keys.length - 1; i++) {
            node = node && node[keys[i]];
            if (node == null) { return; }
        }
        if (node != null) { node[keys[keys.length - 1]] = value; }
    }

    // ---------------------------------------------------------------- undo and redo

    const historyKey = 'agx-history:' + slug;

    function loadHistory() {
        try {
            const raw = JSON.parse(sessionStorage.getItem(historyKey));
            if (raw && Array.isArray(raw.undo) && Array.isArray(raw.redo)) { return raw; }
        } catch (e) { /* storage is off */ }
        return { undo: [], redo: [] };
    }

    function saveHistory(history) {
        history.undo = history.undo.slice(-60);
        try { sessionStorage.setItem(historyKey, JSON.stringify(history)); } catch (e) { /* storage is off */ }
        refreshPanel();
    }

    /** Remembers a finished step so that "Undo" can take it back. */
    function remember(result, message) {
        if (!result || !result.changed || !result.revision_id) { return; }
        const history = loadHistory();
        history.undo.push(result.revision_id);
        history.redo = [];
        saveHistory(history);
        if (message) { notify(message); }
    }

    function reloadWith(after) {
        try { sessionStorage.setItem('agx-after', JSON.stringify(after || {})); } catch (e) { /* storage is off */ }
        window.location.reload();
    }

    async function stepBack() {
        const history = loadHistory();
        const rev = history.undo.pop();
        if (!rev) { return; }
        try {
            const list = (await api('list_revisions', { slug: slug, limit: 200 })).revisions;
            const at = list.findIndex(function (r) { return Number(r.id) === Number(rev); });
            if (at < 0 || !list[at + 1]) { throw new Error('Дальше отменять нечего.'); }
            await api('rollback_revision', { slug: slug, revision_id: Number(list[at + 1].id) });
            history.redo.push(rev);
            saveHistory(history);
            reloadWith({ message: 'Изменение отменено' });
        } catch (e) {
            saveHistory(history);
            notify(e.message, 'error');
        }
    }

    async function stepForward() {
        const history = loadHistory();
        const rev = history.redo.pop();
        if (!rev) { return; }
        try {
            const result = await api('rollback_revision', { slug: slug, revision_id: Number(rev) });
            if (result.revision_id) { history.undo.push(result.revision_id); }
            saveHistory(history);
            reloadWith({ message: 'Изменение возвращено' });
        } catch (e) {
            saveHistory(history);
            notify(e.message, 'error');
        }
    }

    // ---------------------------------------------------------------- shadow root with the interface

    // The site's Content-Security-Policy allows styles only from files of its own origin: the interface styles are a file, and the few
    // style tweaks below go through the CSSOM (el.style), which the policy does not touch.
    const scriptSrc = (document.currentScript && document.currentScript.src) || '/assets/editor.js';
    const cssHref = scriptSrc.replace(/editor\.js(\?|$)/, 'editor.css$1');
    function styleLink() { return h('link', { rel: 'stylesheet', href: cssHref }); }


    const host = h('div', { id: 'agx-host' });
    document.body.appendChild(host);
    const root = host.attachShadow({ mode: 'open' });
    root.appendChild(styleLink());

    // ---------------------------------------------------------------- blocks: overlay, panel, strips

    const outline = h('div', { class: 'outline' });
    const bar = h('div', { class: 'bar' });
    const addTop = h('button', { class: 'add', type: 'button', 'aria-label': 'Добавить блок выше' }, [icon('plus'), 'Добавить блок']);
    const addBottom = h('button', { class: 'add', type: 'button', 'aria-label': 'Добавить блок ниже' }, [icon('plus'), 'Добавить блок']);
    const dropLine = h('div', { class: 'drop' });
    const dragGhost = h('div', { class: 'ghost' });
    [outline, dropLine, bar, addTop, addBottom, dragGhost].forEach(function (el) { root.appendChild(el); });

    let current = null;      // wrapper of the hovered block
    let busy = false;        // dragging or a dialog is open: the panel stays still
    let confirmDelete = false;
    let drag = null;

    const blockData = {};
    const types = {};
    if (editorData) {
        editorData.blocks.forEach(function (b) { blockData[b.id] = b; });
        editorData.types.forEach(function (t) { types[t.type] = t; });
    }

    function wraps() { return Array.prototype.slice.call(document.querySelectorAll('[data-ag-wrap]')); }

    // The wrapper has no box of its own, so the theme looks the same as for a visitor.
    wraps().forEach(function (w) { w.style.display = 'contents'; });

    function typeTitle(wrap) {
        const t = types[wrap.dataset.agType];
        return t ? t.title : wrap.dataset.agType;
    }

    function rectOf(wrap) {
        let r = null;
        Array.prototype.forEach.call(wrap.children, function (c) {
            if (/^(SCRIPT|STYLE|TEMPLATE|NOSCRIPT|LINK)$/.test(c.tagName)) { return; }
            const b = c.getBoundingClientRect();
            if (b.width < 1 || b.height < 1) { return; }
            r = r ? { left: Math.min(r.left, b.left), top: Math.min(r.top, b.top), right: Math.max(r.right, b.right), bottom: Math.max(r.bottom, b.bottom) } : { left: b.left, top: b.top, right: b.right, bottom: b.bottom };
        });
        if (r) { r.width = r.right - r.left; r.height = r.bottom - r.top; }
        return r;
    }

    /** Lower edge of what the theme pins to the top of the screen (its header, the black editing strip). */
    function safeTop() {
        let y = 0;
        document.querySelectorAll('.ag-bar, header, [role=banner]').forEach(function (el) {
            const pos = window.getComputedStyle(el).position;
            if (pos === 'fixed' || pos === 'sticky') { y = Math.max(y, el.getBoundingClientRect().bottom); }
        });
        return y;
    }

    function hideHover() {
        outline.style.display = 'none';
        bar.style.display = 'none';
        addTop.style.display = 'none';
        addBottom.style.display = 'none';
    }

    function place(el, x, y) {
        el.style.left = Math.round(x) + 'px';
        el.style.top = Math.round(y) + 'px';
    }

    function layout() {
        if (!current || !document.contains(current)) { current = null; hideHover(); return; }
        const r = rectOf(current);
        if (!r || r.bottom < 0 || r.top > window.innerHeight) { hideHover(); return; }
        const top = safeTop();

        outline.style.display = 'block';
        outline.style.left = Math.round(r.left) + 'px';
        outline.style.top = Math.round(r.top) + 'px';
        outline.style.width = Math.round(r.width) + 'px';
        outline.style.height = Math.round(r.height) + 'px';
        outline.classList.toggle('source', !!drag && drag.wrap === current);

        if (busy) {
            // While dragging, the panel stays in the page (hidden) because it holds the pointer.
            bar.style.visibility = drag ? 'hidden' : '';
            if (!drag) { hideHover(); }
            addTop.style.display = 'none';
            addBottom.style.display = 'none';
            return;
        }

        bar.style.visibility = '';
        bar.style.display = 'flex';
        const barWidth = bar.offsetWidth;
        const left = Math.max(8, Math.min(r.right, window.innerWidth) - barWidth - 10);
        const lowest = Math.min(r.bottom - bar.offsetHeight - 6, window.innerHeight - bar.offsetHeight - 8);
        place(bar, left, Math.max(Math.min(Math.max(r.top + 8, top + 8), lowest), r.top + 4));

        // Left side of the block: the panel with the block's own buttons sits on the right.
        const cx = Math.max(r.left, 0) + 110;
        addTop.style.display = r.top < window.innerHeight - 80 ? 'inline-flex' : 'none';
        place(addTop, cx, Math.max(r.top, top + 18));
        addBottom.style.display = r.bottom > top + 40 ? 'inline-flex' : 'none';
        place(addBottom, cx, Math.min(r.bottom, window.innerHeight - 24));
    }

    let layoutQueued = false;
    function scheduleLayout() {
        if (layoutQueued) { return; }
        layoutQueued = true;
        window.requestAnimationFrame(function () { layoutQueued = false; layout(); });
    }

    function button(iconName, label, title, onClick, extra) {
        const kids = [icon(iconName)];
        if (label) { kids.push(h('span', { class: 't', text: label })); }
        return h('button', Object.assign({ type: 'button', title: title, 'aria-label': title, onclick: onClick }, extra || {}), kids);
    }

    function renderBar() {
        bar.textContent = '';
        if (!current) { return; }
        const all = wraps();
        const at = all.indexOf(current);
        const id = current.dataset.agWrap;

        if (confirmDelete) {
            bar.appendChild(h('span', { class: 'ask', text: 'Удалить этот блок?' }));
            bar.appendChild(h('button', { type: 'button', class: 'yes', text: 'Да, удалить', onclick: function () { confirmDelete = false; removeBlock(id); } }));
            bar.appendChild(h('button', { type: 'button', text: 'Нет', onclick: function () { confirmDelete = false; renderBar(); layout(); } }));
            return;
        }
        bar.appendChild(h('span', { class: 'name', text: typeTitle(current) }));
        const grip = button('grip', '', 'Потяните, чтобы переместить блок', null, { class: 'grip' });
        grip.addEventListener('pointerdown', function (e) { startDrag(e, current); });
        bar.appendChild(grip);
        bar.appendChild(button('up', '', 'Выше', function () { shift(id, -1); }, at <= 0 ? { disabled: true } : {}));
        bar.appendChild(button('down', '', 'Ниже', function () { shift(id, 1); }, at >= all.length - 1 ? { disabled: true } : {}));
        bar.appendChild(h('span', { class: 'sep' }));
        bar.appendChild(button('edit', 'Изменить', 'Изменить содержимое блока', function () { openForm(id); }, { class: 'primary' }));
        bar.appendChild(button('copy', '', 'Сделать копию блока', function () { copyBlock(id); }));
        bar.appendChild(button('trash', '', 'Удалить блок', function () { confirmDelete = true; renderBar(); layout(); }, { class: 'danger' }));
    }

    function setCurrent(wrap) {
        if (wrap === current) { return; }
        current = wrap;
        confirmDelete = false;
        renderBar();
        layout();
    }

    document.addEventListener('mousemove', function (e) {
        if (busy) { return; }
        if (e.target === host) { return; }
        const wrap = e.target && e.target.closest ? e.target.closest('[data-ag-wrap]') : null;
        setCurrent(wrap);
    }, { passive: true });
    document.addEventListener('mouseleave', function () { if (!busy) { setCurrent(null); } });
    window.addEventListener('scroll', scheduleLayout, { passive: true });
    window.addEventListener('resize', scheduleLayout);
    if (window.ResizeObserver) { new ResizeObserver(scheduleLayout).observe(document.body); }

    // ---------------------------------------------------------------- block actions

    function flashBlock(id) {
        const wrap = document.querySelector('[data-ag-wrap="' + id + '"]');
        if (!wrap) { return; }
        wrap.scrollIntoView({ block: 'center' });
        const r = rectOf(wrap);
        if (!r) { return; }
        outline.className = 'outline flash';
        outline.style.cssText = 'display:block;left:' + r.left + 'px;top:' + r.top + 'px;width:' + r.width + 'px;height:' + r.height + 'px';
        window.setTimeout(function () { outline.className = 'outline'; layout(); }, 1500);
    }

    async function run(label, action) {
        busy = true;
        layout();
        try {
            const result = await action;
            remember(result, label);
            return result;
        } catch (e) {
            notify(e.message, 'error');
            return null;
        } finally {
            busy = false;
            renderBar();
            layout();
        }
    }

    function afterOf(wrap) {
        const all = wraps();
        const at = all.indexOf(wrap);
        return at <= 0 ? '' : all[at - 1].dataset.agWrap;
    }

    async function shift(id, step) {
        const all = wraps();
        const at = all.findIndex(function (w) { return w.dataset.agWrap === id; });
        const to = at + step;
        if (at < 0 || to < 0 || to >= all.length) { return; }
        const wrap = all[at];
        const before = rectOf(wrap);
        // The new place: behind the block we jump over (down) or behind the one before it (up).
        const after = step > 0 ? all[to].dataset.agWrap : (to === 0 ? '' : all[to - 1].dataset.agWrap);
        if (step > 0) { all[to].after(wrap); } else { all[to].before(wrap); }
        const now = rectOf(wrap);
        if (before && now) { window.scrollBy(0, now.top - before.top); }
        renderBar();
        layout();
        const res = await run('Блок перемещён', api('move_block', { slug: slug, block_id: id, after: after }));
        if (res === null) { reloadWith({}); }
    }

    async function removeBlock(id) {
        const result = await run('Блок удалён', api('delete_block', { slug: slug, block_id: id }));
        if (!result) { return; }
        const wrap = document.querySelector('[data-ag-wrap="' + id + '"]');
        if (wrap) { wrap.remove(); }
        current = null;
        hideHover();
        syncEnd();
    }

    async function copyBlock(id) {
        const result = await run('', api('duplicate_block', { slug: slug, block_id: id }));
        if (result) { reloadWith({ flash: result.block_id, message: 'Копия блока добавлена ниже' }); }
    }

    // ---------------------------------------------------------------- drag and drop of blocks

    function startDrag(e, wrap) {
        if (e.button !== undefined && e.button !== 0) { return; }
        e.preventDefault();
        const target = e.currentTarget;
        const id = wrap.dataset.agWrap;
        drag = { wrap: wrap, id: id, slot: null, y: e.clientY, raf: 0 };
        busy = true;
        try { target.setPointerCapture(e.pointerId); } catch (err) { /* old browser */ }
        dragGhost.textContent = typeTitle(wrap);
        dragGhost.style.display = 'block';
        place(dragGhost, e.clientX + 14, e.clientY + 14);
        layout();
        target.addEventListener('pointermove', onDragMove);
        target.addEventListener('pointerup', onDragEnd);
        target.addEventListener('pointercancel', onDragCancel);
        document.addEventListener('keydown', onDragKey, true);
        drag.target = target;
        autoScroll();
    }

    /** Slot = index among the other blocks before which the dragged one lands (length = at the end). */
    function slotAt(y) {
        const others = wraps().filter(function (w) { return w !== drag.wrap; });
        for (let i = 0; i < others.length; i++) {
            const r = rectOf(others[i]);
            if (r && y < r.top + r.height / 2) { return { index: i, others: others, y: r.top }; }
        }
        const last = others.length ? rectOf(others[others.length - 1]) : null;
        return { index: others.length, others: others, y: last ? last.bottom : 0 };
    }

    function onDragMove(e) {
        if (!drag) { return; }
        drag.y = e.clientY;
        place(dragGhost, e.clientX + 14, e.clientY + 14);
        drag.slot = slotAt(e.clientY);
        dropLine.style.display = 'block';
        dropLine.style.top = Math.round(drag.slot.y) + 'px';
    }

    function autoScroll() {
        if (!drag) { return; }
        const edge = 80;
        const top = Math.max(safeTop(), 0) + 24;
        if (drag.y < top + edge) { window.scrollBy(0, -Math.ceil((top + edge - drag.y) / 5)); }
        else if (drag.y > window.innerHeight - edge) { window.scrollBy(0, Math.ceil((drag.y - (window.innerHeight - edge)) / 5)); }
        drag.slot = slotAt(drag.y);
        dropLine.style.top = Math.round(drag.slot.y) + 'px';
        drag.raf = window.requestAnimationFrame(autoScroll);
    }

    function endDrag() {
        if (!drag) { return null; }
        window.cancelAnimationFrame(drag.raf);
        const t = drag.target;
        t.removeEventListener('pointermove', onDragMove);
        t.removeEventListener('pointerup', onDragEnd);
        t.removeEventListener('pointercancel', onDragCancel);
        document.removeEventListener('keydown', onDragKey, true);
        dropLine.style.display = 'none';
        dragGhost.style.display = 'none';
        const finished = drag;
        drag = null;
        busy = false;
        return finished;
    }

    function onDragCancel() { endDrag(); renderBar(); layout(); }
    function onDragKey(e) { if (e.key === 'Escape') { e.stopPropagation(); onDragCancel(); } }

    async function onDragEnd(e) {
        if (drag) { drag.slot = slotAt(e.clientY); }
        const d = endDrag();
        if (!d || !d.slot) { renderBar(); layout(); return; }
        const others = d.slot.others;
        const all = wraps();
        const from = all.indexOf(d.wrap);
        if (d.slot.index === from) { renderBar(); layout(); return; }   // dropped where it was
        const after = d.slot.index === 0 ? '' : others[d.slot.index - 1].dataset.agWrap;
        const before = rectOf(d.wrap);
        if (d.slot.index >= others.length) { others[others.length - 1].after(d.wrap); }
        else { others[d.slot.index].before(d.wrap); }
        const now = rectOf(d.wrap);
        if (before && now) { window.scrollBy(0, now.top - before.top); }
        setCurrent(d.wrap);
        renderBar();
        const res = await run('Блок перемещён', api('move_block', { slug: slug, block_id: d.id, after: after }));
        if (res === null) { reloadWith({}); }
    }

    // ---------------------------------------------------------------- dialog

    let dialog = null;

    /** Opens a dialog. Returns {body, footer, setError, close}. onClose() may veto with false. */
    function openDialog(title, subtitle, options) {
        options = options || {};
        closeDialog(true);
        busy = true;
        hideHover();
        const body = h('div', { class: 'body' });
        const footer = h('footer');
        const errorEl = h('div', { class: 'error', role: 'alert' });
        footer.appendChild(errorEl);
        const modal = h('div', { class: 'modal' + (options.wide ? ' wide' : ''), role: 'dialog', 'aria-modal': 'true', 'aria-label': title }, [
            h('header', {}, [
                h('div', {}, [h('h2', { text: title }), subtitle ? h('p', { class: 'sub', text: subtitle }) : null]),
                h('button', { class: 'x', type: 'button', 'aria-label': 'Закрыть', onclick: function () { attemptClose(); } }, [icon('close')])
            ]),
            body,
            footer
        ]);
        const backdrop = h('div', { class: 'backdrop' }, [modal]);
        backdrop.addEventListener('mousedown', function (e) { if (e.target === backdrop) { attemptClose(); } });
        root.appendChild(backdrop);
        function attemptClose() {
            if (options.onClose && options.onClose() === false) { return; }
            closeDialog();
        }
        dialog = {
            backdrop: backdrop, body: body, footer: footer, attemptClose: attemptClose,
            setError: function (text) { errorEl.textContent = text || ''; }
        };
        return dialog;
    }

    function closeDialog(quiet) {
        if (!dialog) { return; }
        dialog.backdrop.remove();
        dialog = null;
        if (!quiet) { busy = false; renderBar(); layout(); }
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && dialog) { e.preventDefault(); dialog.attemptClose(); }
    });

    // ---------------------------------------------------------------- add block

    function requiredMedia(type) {
        return types[type].fields.filter(function (f) { return f.media && f.required; });
    }

    function pickFile() {
        return new Promise(function (resolve) {
            const input = h('input', { type: 'file', accept: 'image/*' });
            input.addEventListener('change', function () { resolve(input.files && input.files[0] ? input.files[0] : null); });
            input.addEventListener('cancel', function () { resolve(null); });
            input.click();
        });
    }

    /** after: id of the block to follow, '' for the very top, null for the end. */
    function openPicker(after) {
        if (!editorData) { return; }
        const where = after === '' ? 'в начало страницы' : (after === null ? 'в конец страницы' : 'после блока «' + typeTitle(document.querySelector('[data-ag-wrap="' + after + '"]')) + '»');
        const dlg = openDialog('Добавить блок', 'Блок встанет ' + where + '. Потом его можно перетащить.', { wide: true });
        const grid = h('div', { class: 'types' });
        editorData.types.forEach(function (t) {
            grid.appendChild(h('button', { class: 'type', type: 'button', onclick: function () { addBlock(t.type, after, dlg); } }, [
                h('b', { text: t.title }),
                t.hint ? h('span', { text: t.hint }) : null
            ]));
        });
        dlg.body.appendChild(grid);
        dlg.footer.appendChild(h('button', { class: 'btn', type: 'button', text: 'Отмена', onclick: function () { dlg.attemptClose(); } }));
    }

    async function addBlock(type, after, dlg) {
        let data;
        try {
            const media = requiredMedia(type);
            if (media.length) {
                dlg.setError('Выберите картинку для блока...');
                data = {};
                for (const f of media) {
                    const file = await pickFile();
                    if (!file) { dlg.setError(''); return; }
                    data[f.name] = await upload(file);
                }
                types[type].fields.forEach(function (f) {
                    if (f.required && !(f.name in data) && (f.type === 'string' || f.type === 'text')) { data[f.name] = f.label; }
                });
            }
            const args = { slug: slug, type: type };
            if (after !== null) { args.after = after; }
            if (data) { args.data = data; }
            const result = await api('add_block', args);
            remember(result, '');
            reloadWith({ flash: result.block_id, message: 'Блок добавлен', edit: media.length ? result.block_id : null });
        } catch (e) {
            dlg.setError(e.message);
        }
    }

    addTop.addEventListener('click', function () { if (current) { openPicker(afterOf(current)); } });
    addBottom.addEventListener('click', function () { if (current) { openPicker(current.dataset.agWrap); } });

    // ---------------------------------------------------------------- form of a block

    /** Builds the input of one field. Returns {el, get(), check()}. */
    function buildField(f, value) {
        if (f.type === 'list') { return buildList(f, value); }
        if (f.type === 'bool') {
            const box = h('input', { type: 'checkbox' });
            box.checked = value === true;
            return {
                el: h('div', { class: 'fld' }, [h('label', { class: 'switch' }, [box, h('span', { class: 'lab', text: f.label })]), f.hint ? h('p', { class: 'hint', text: f.hint }) : null]),
                get: function () { return box.checked; },
                check: function () { return true; }
            };
        }
        let input;
        let get;
        let empty;
        if (f.media) {
            return buildMedia(f, value);
        } else if (f.type === 'text') {
            input = h('textarea', f.max ? { maxlength: f.max } : {});
            input.value = value == null ? '' : String(value);
            get = function () { return input.value.replace(/\r\n/g, '\n'); };
            const grow = function () { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight + 4, 420) + 'px'; };
            input.addEventListener('input', grow);
            window.setTimeout(grow, 0);
        } else if (f.type === 'int') {
            input = h('input', { type: 'number', step: '1' });
            if (f.min != null) { input.min = f.min; }
            if (f.max != null) { input.max = f.max; }
            input.value = value == null ? '' : String(value);
            get = function () { return input.value === '' ? null : parseInt(input.value, 10); };
        } else if (f.type === 'enum') {
            input = h('select');
            (f.options || []).forEach(function (o) { input.appendChild(h('option', { value: o.value, text: o.label })); });
            input.value = value == null ? (f.options && f.options.length ? f.options[0].value : '') : String(value);
            get = function () { return input.value; };
        } else {
            input = h('input', Object.assign({ type: 'text' }, f.max ? { maxlength: f.max } : {}, f.type === 'url' ? { placeholder: 'https://... или /страница, #якорь, tel:, mailto:', spellcheck: 'false' } : {}));
            input.value = value == null ? '' : String(value);
            get = function () { return input.value.replace(/[\r\n]+/g, ' ').trim(); };
        }
        empty = function () { const v = get(); return v === '' || v === null; };
        const fld = h('div', { class: 'fld' }, [
            h('label', { class: 'lab' }, [f.label, f.required ? h('span', { class: 'req', text: ' *' }) : null]),
            input,
            f.hint ? h('p', { class: 'hint', text: f.hint }) : null
        ]);
        input.addEventListener('input', function () { fld.classList.remove('bad'); });
        return {
            el: fld, get: get,
            check: function () {
                if (f.required && empty()) { fld.classList.add('bad'); input.focus(); return false; }
                return true;
            }
        };
    }

    function buildMedia(f, value) {
        const prev = h('div', { class: 'prev' });
        const input = h('input', { type: 'text', placeholder: 'или вставьте ссылку на картинку', spellcheck: 'false' });
        input.value = value == null ? '' : String(value);
        const tip = h('div', { class: 'tip', text: 'Перетащите картинку сюда или нажмите «Загрузить». Она сожмётся сама.' });
        const fld = h('div', { class: 'fld' });
        const box = h('div', { class: 'media' });

        function paint() {
            const src = input.value.trim();
            prev.textContent = '';
            prev.style.backgroundImage = src ? 'url("' + src.replace(/["\\\n]/g, '') + '")' : '';
            if (!src) { prev.appendChild(icon('image')); }
        }
        async function take(file) {
            if (!file) { return; }
            tip.textContent = 'Загружаю...';
            try {
                input.value = await upload(file);
                tip.textContent = 'Готово. Картинка сохранится вместе с блоком.';
                fld.classList.remove('bad');
                paint();
            } catch (e) {
                tip.textContent = e.message;
            }
        }
        const choose = h('button', { class: 'btn small', type: 'button', text: 'Загрузить', onclick: async function () { take(await pickFile()); } });
        const drop = f.required ? null : h('button', { class: 'btn small', type: 'button', text: 'Убрать', onclick: function () { input.value = ''; paint(); } });
        box.addEventListener('dragover', function (e) { e.preventDefault(); box.classList.add('over'); });
        box.addEventListener('dragleave', function () { box.classList.remove('over'); });
        box.addEventListener('drop', function (e) {
            e.preventDefault();
            box.classList.remove('over');
            take(e.dataTransfer && e.dataTransfer.files ? e.dataTransfer.files[0] : null);
        });
        input.addEventListener('change', paint);
        box.appendChild(prev);
        box.appendChild(h('div', { class: 'side' }, [h('div', { class: 'row' }, [choose, drop]), input, tip]));
        fld.appendChild(h('label', { class: 'lab' }, [f.label, f.required ? h('span', { class: 'req', text: ' *' }) : null]));
        fld.appendChild(box);
        paint();
        return {
            el: fld,
            get: function () { return input.value.trim(); },
            check: function () {
                if (f.required && input.value.trim() === '') { fld.classList.add('bad'); return false; }
                return true;
            }
        };
    }

    function buildObject(fields, values) {
        const el = h('div');
        const parts = fields.map(function (f) {
            const part = buildField(f, values ? values[f.name] : undefined);
            el.appendChild(part.el);
            return { f: f, part: part };
        });
        return {
            el: el,
            get: function () {
                const out = {};
                parts.forEach(function (p) { out[p.f.name] = p.part.get(); });
                return out;
            },
            check: function () {
                let ok = true;
                parts.forEach(function (p) { if (!p.part.check()) { ok = false; } });
                return ok;
            },
            summary: function () {
                for (let i = 0; i < parts.length; i++) {
                    if (parts[i].f.type === 'string' || parts[i].f.type === 'text') {
                        const v = parts[i].part.get();
                        if (v) { return String(v).split('\n')[0]; }
                    }
                }
                return '';
            }
        };
    }

    function buildList(f, value) {
        const items = h('div', { class: 'items' });
        const count = h('span', { class: 'hint' });
        const addButton = h('button', { class: 'btn small', type: 'button' }, [icon('plus'), ' Добавить']);
        const list = Array.isArray(value) ? value : [];

        function refresh() {
            const n = items.children.length;
            count.textContent = n ? ' (' + n + ')' : '';
            addButton.disabled = f.max != null && n >= f.max;
        }

        function addItem(values, open) {
            const obj = buildObject(f.of || [], values);
            const sum = h('span', { class: 'sum' });
            const chev = icon('chevron');
            chev.classList.add('chev');
            const card = h('div', { class: 'item' + (open ? ' open' : '') });
            const move = function (step) {
                const sibling = step < 0 ? card.previousElementSibling : card.nextElementSibling;
                if (sibling) { step < 0 ? items.insertBefore(card, sibling) : items.insertBefore(sibling, card); }
            };
            const top = h('div', { class: 'top' }, [
                h('span', { class: 'grip', title: 'Потяните, чтобы переставить' }, [icon('grip')]),
                chev,
                sum,
                h('button', { class: 'mini', type: 'button', title: 'Выше', onclick: function (e) { e.stopPropagation(); move(-1); } }, [icon('up')]),
                h('button', { class: 'mini', type: 'button', title: 'Ниже', onclick: function (e) { e.stopPropagation(); move(1); } }, [icon('down')]),
                h('button', { class: 'mini del', type: 'button', title: 'Удалить', onclick: function (e) { e.stopPropagation(); card.remove(); refresh(); } }, [icon('trash')])
            ]);
            const inner = h('div', { class: 'in' }, [obj.el]);
            const paint = function () {
                const text = obj.summary();
                sum.textContent = text || 'Без названия';
                sum.classList.toggle('empty', !text);
            };
            inner.addEventListener('input', paint);
            top.addEventListener('click', function () { card.classList.toggle('open'); });
            card.appendChild(top);
            card.appendChild(inner);
            card._obj = obj;
            paint();
            items.appendChild(card);
            refresh();
            return card;
        }

        list.forEach(function (v, i) { addItem(v, list.length <= 3 && i === 0); });
        makeSortable(items);
        addButton.addEventListener('click', function () {
            const card = addItem({}, true);
            card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            const first = card.querySelector('input,textarea');
            if (first) { window.setTimeout(function () { first.focus({ preventScroll: true }); }, 50); }
        });
        refresh();

        const fld = h('div', { class: 'fld' }, [
            h('div', { class: 'lab' }, [f.label, count]),
            items,
            h('div', { class: 'addrow' }, [addButton])
        ]);
        return {
            el: fld,
            get: function () { return Array.prototype.map.call(items.children, function (c) { return c._obj.get(); }); },
            check: function () {
                let ok = true;
                Array.prototype.forEach.call(items.children, function (c) {
                    if (!c._obj.check()) { ok = false; c.classList.add('open'); }
                });
                return ok;
            }
        };
    }

    /** Pointer-driven reordering of the cards of a list by their grip. */
    function makeSortable(container) {
        container.addEventListener('pointerdown', function (e) {
            const grip = e.target.closest ? e.target.closest('.grip') : null;
            const card = grip ? grip.closest('.item') : null;
            if (!card || card.parentNode !== container) { return; }
            e.preventDefault();
            card.classList.add('moving');
            // The card moves in the DOM while it is dragged, and a moved element loses pointer capture: follow the pointer on the document.
            try { grip.releasePointerCapture(e.pointerId); } catch (err) { /* no capture to release */ }
            const scroller = container.closest('.body');
            const move = function (ev) {
                const y = ev.clientY;
                const sibs = Array.prototype.filter.call(container.children, function (c) { return c !== card; });
                let target = null;
                for (let i = 0; i < sibs.length; i++) {
                    const r = sibs[i].getBoundingClientRect();
                    if (y < r.top + r.height / 2) { target = sibs[i]; break; }
                }
                if (target !== card.nextElementSibling) { container.insertBefore(card, target); }
                if (scroller) {
                    const box = scroller.getBoundingClientRect();
                    if (y < box.top + 40) { scroller.scrollTop -= 14; }
                    else if (y > box.bottom - 40) { scroller.scrollTop += 14; }
                }
            };
            const end = function () {
                card.classList.remove('moving');
                document.removeEventListener('pointermove', move);
                document.removeEventListener('pointerup', end);
                document.removeEventListener('pointercancel', end);
            };
            document.addEventListener('pointermove', move);
            document.addEventListener('pointerup', end);
            document.addEventListener('pointercancel', end);
        });
    }

    function openForm(id) {
        const block = blockData[id];
        const type = block ? types[block.type] : null;
        if (!block || !type) { notify('Этот блок нельзя изменить мышью.', 'error'); return; }
        let dirty = false;
        let saving = false;
        const dlg = openDialog('Изменить: ' + type.title, type.hint, {
            wide: true,
            onClose: function () { return !dirty || saving || window.confirm('Закрыть без сохранения? Изменения в этом окне пропадут.'); }
        });
        const form = buildObject(type.fields, block.data);
        dlg.body.appendChild(form.el);
        dlg.body.addEventListener('input', function () { dirty = true; });
        dlg.body.addEventListener('change', function () { dirty = true; });
        dlg.body.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('.mini, .btn')) { dirty = true; } });
        const save = h('button', { class: 'btn main', type: 'button', text: 'Сохранить' });
        dlg.footer.appendChild(h('button', { class: 'btn', type: 'button', text: 'Отмена', onclick: function () { dlg.attemptClose(); } }));
        dlg.footer.appendChild(save);
        save.addEventListener('click', async function () {
            dlg.setError('');
            if (!form.check()) { dlg.setError('Заполните отмеченные поля.'); return; }
            saving = true;
            save.disabled = true;
            save.textContent = 'Сохраняю...';
            try {
                const result = await api('update_block_data', { slug: slug, block_id: id, payload: form.get() });
                remember(result, '');
                reloadWith({ flash: id, message: result.changed ? 'Сохранено' : 'Без изменений' });
            } catch (e) {
                saving = false;
                save.disabled = false;
                save.textContent = 'Сохранить';
                dlg.setError(e.message);
            }
        });
        const first = dlg.body.querySelector('input:not([type=checkbox]),textarea');
        if (first) { window.setTimeout(function () { first.focus({ preventScroll: true }); }, 30); }
    }

    // ---------------------------------------------------------------- bottom panel: messages, undo, redo

    const panelMsg = h('span', { class: 'msg' });
    const undoBtn = h('button', { type: 'button', title: 'Отменить последнее изменение (Ctrl+Z)', onclick: stepBack }, [icon('undo'), 'Отменить']);
    const redoBtn = h('button', { type: 'button', title: 'Вернуть отменённое (Ctrl+Shift+Z)', onclick: stepForward }, [icon('redo'), 'Вернуть']);
    const hideBtn = h('button', { class: 'hide', type: 'button', title: 'Скрыть подсказку', 'aria-label': 'Скрыть подсказку', onclick: function () { panelMsg.textContent = ''; panelMsg.style.display = 'none'; hideBtn.style.display = 'none'; } }, [icon('close')]);
    const panel = h('div', { class: 'panel' }, [panelMsg, undoBtn, redoBtn, hideBtn]);
    root.appendChild(panel);
    let notifyTimer = 0;

    function notify(text, kind) {
        say(text);
        if (!text) { return; }
        panelMsg.textContent = text;
        panelMsg.className = 'msg' + (kind === 'error' ? ' error' : '');
        panelMsg.style.display = '';
        hideBtn.style.display = '';
        window.clearTimeout(notifyTimer);
        notifyTimer = window.setTimeout(function () { panelMsg.textContent = ''; panelMsg.style.display = 'none'; hideBtn.style.display = 'none'; }, kind === 'error' ? 9000 : 4500);
    }

    function refreshPanel() {
        const history = loadHistory();
        undoBtn.disabled = history.undo.length === 0;
        redoBtn.disabled = history.redo.length === 0;
    }

    document.addEventListener('keydown', function (e) {
        if (!(e.ctrlKey || e.metaKey) || e.altKey || e.key.toLowerCase() !== 'z' || dialog) { return; }
        const t = e.target;
        if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) { return; }
        e.preventDefault();
        if (e.shiftKey) { stepForward(); } else { stepBack(); }
    });

    // ---------------------------------------------------------------- hidden blocks and the end of the page

    /** A block without anything to see (settings of a calculator, for example) gets a strip to hover. */
    function markHidden() {
        wraps().forEach(function (wrap) {
            if (wrap.querySelector('[data-ag-ghost]')) { return; }
            if (rectOf(wrap)) { return; }
            const ghost = h('div', {
                'data-ag-ghost': '',
                text: 'Служебный блок «' + typeTitle(wrap) + '». На странице он не виден, но управляет сайтом. Наведите курсор, чтобы изменить.'
            });
            ghost.style.cssText = 'margin:8px auto;max-width:960px;padding:14px 18px;border:2px dashed #f5b301;border-radius:10px;background:rgba(245,179,1,.1);color:#444;font:14px/1.4 system-ui,sans-serif;text-align:center';
            wrap.appendChild(ghost);
        });
    }

    let endHost = null;
    function syncEnd() {
        const end = document.querySelector('[data-ag-end]');
        if (!end || !editorData) { return; }
        if (!endHost) {
            endHost = h('div');
            endHost.style.cssText = 'display:block;position:static;width:auto;height:auto;margin:0 auto;max-width:960px;padding:24px 16px 120px';
            end.appendChild(endHost);
            const sh = endHost.attachShadow({ mode: 'open' });
            sh.appendChild(styleLink());
            sh.appendChild(h('button', { class: 'endbtn', type: 'button', onclick: function () { const all = wraps(); openPicker(all.length ? all[all.length - 1].dataset.agWrap : null); } }, [icon('plus'), wraps().length ? 'Добавить блок в конец страницы' : 'Страница пуста. Добавьте первый блок']));
        }
        const label = endHost.shadowRoot.querySelector('.endbtn');
        label.lastChild.textContent = wraps().length ? 'Добавить блок в конец страницы' : 'Страница пуста. Добавьте первый блок';
    }

    // ---------------------------------------------------------------- text in place

    function saveText(el, value) {
        el.classList.remove('ag-error');
        el.classList.add('ag-saving');
        say('Сохранение...');
        return api('update_block_field', { slug: el.dataset.agSlug, block_id: el.dataset.agBlock, field_path: el.dataset.agField, value: value })
            .then(function (result) {
                el.classList.remove('ag-saving');
                el.dataset.agSaved = value;
                const known = blockData[el.dataset.agBlock];
                if (known) { setPath(known.data, el.dataset.agField, value); }
                if (result.changed) { remember(result, 'Сохранено'); } else { say('Без изменений'); }
            })
            .catch(function (e) {
                el.classList.remove('ag-saving');
                el.classList.add('ag-error');
                notify(e.message, 'error');
            });
    }

    document.querySelectorAll('[data-ag-field]').forEach(function (el) {
        const multi = el.hasAttribute('data-ag-multi');
        el.setAttribute('contenteditable', 'true');
        el.setAttribute('spellcheck', 'true');
        el.dataset.agSaved = el.innerText.trim();
        let timer = null;
        const read = function () { return el.innerText.replace(/ /g, ' ').trim(); };
        const flush = function () {
            clearTimeout(timer);
            const value = read();
            if (value !== el.dataset.agSaved) { saveText(el, value); }
        };

        el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !multi) { e.preventDefault(); el.blur(); }
            if (e.key === 'Escape') { el.innerText = el.dataset.agSaved; el.blur(); }
        });
        // Plain text only: pasted HTML would never pass the schema anyway.
        el.addEventListener('paste', function (e) {
            e.preventDefault();
            const text = (e.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, multi ? text : text.replace(/\s*\n\s*/g, ' '));
        });
        el.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(flush, 800);
        });
        el.addEventListener('blur', flush);
    });

    // ---------------------------------------------------------------- start

    function start() {
        refreshPanel();
        markHidden();
        syncEnd();
        let after = null;
        try {
            after = JSON.parse(sessionStorage.getItem('agx-after'));
            sessionStorage.removeItem('agx-after');
        } catch (e) { after = null; }
        if (after && after.message) { notify(after.message); }
        if (after && after.flash) { window.setTimeout(function () { flashBlock(after.flash); }, 60); }
        if (after && after.edit) { window.setTimeout(function () { openForm(after.edit); }, 400); }
        let seen = false;
        try { seen = window.localStorage.getItem('agx-hint') === '1'; window.localStorage.setItem('agx-hint', '1'); } catch (e) { /* storage is off */ }
        if (!seen && !(after && after.message)) {
            notify('Наведите курсор на блок: появится панель. Потяните за значок из точек, чтобы переместить блок. Текст меняется кликом.');
            window.clearTimeout(notifyTimer);
            notifyTimer = window.setTimeout(function () { panelMsg.textContent = ''; panelMsg.style.display = 'none'; hideBtn.style.display = 'none'; }, 15000);
        } else if (!after || !after.message) {
            panelMsg.style.display = 'none';
            hideBtn.style.display = 'none';
        }
    }

    if (editorData) {
        if (document.readyState === 'complete') { start(); } else { window.addEventListener('load', start); }
    } else {
        // An old theme page without editor data: only the text editing works.
        panel.style.display = 'none';
    }
}());
