// Visual editor of "richtext" fields of AgeCMS: a contenteditable box and a row of buttons (bold, italic, link, H2, H3,
// list, numbered list, quote), without libraries: the page policy lets in scripts of the site only. Used by the page editor
// (assets/editor.js loads it when a page needs it) and by the block editor of the admin.
// clean() keeps pasted text tidy and the saved value small; the server cleans the HTML again (core/RichText.php).
(function () {
    'use strict';
    if (window.AgeRich) { return; }

    const ALLOWED = {
        P: [], BR: [], H2: [], H3: [], H4: [], STRONG: [], B: [], EM: [], I: [], U: [], S: [], A: ['href', 'title', 'rel'],
        UL: [], OL: [], LI: [], BLOCKQUOTE: [], FIGURE: [], FIGCAPTION: [], IMG: ['src', 'alt', 'width', 'height'],
        TABLE: [], THEAD: [], TBODY: [], TR: [], TH: ['colspan', 'rowspan'], TD: ['colspan', 'rowspan'], HR: [], CODE: [], PRE: []
    };
    const DROP = /^(SCRIPT|STYLE|IFRAME|FRAME|OBJECT|EMBED|NOSCRIPT|TEMPLATE|SVG|MATH|HEAD|TITLE|META|LINK|BASE|FORM|INPUT|BUTTON|SELECT|OPTION|TEXTAREA|CANVAS|AUDIO|VIDEO|PICTURE|SOURCE|MAP|DIALOG)$/;
    const RENAME = { H1: 'H2', H5: 'H4', H6: 'H4', STRIKE: 'S', DEL: 'S', INS: 'U' };
    const CONTAINER = /^(DIV|SECTION|ARTICLE|HEADER|FOOTER|MAIN|ASIDE|NAV|ADDRESS|CENTER|DL|DT|DD)$/;
    const BLOCK = /^(P|H2|H3|H4|UL|OL|LI|BLOCKQUOTE|FIGURE|FIGCAPTION|TABLE|THEAD|TBODY|TR|TH|TD|HR|PRE)$/;
    const KEEP_EMPTY = /^(BR|HR|IMG|TD|TH)$/;

    /** Same rule as RichText::href(): http(s), mailto:, tel:, a path, ?query or #anchor. */
    function safeHref(value) {
        const url = String(value || '').trim().replace(/[\t\n\r]/g, '');
        if (!url || url.length > 2000 || /[\u0000-\u001f\u007f<>"\\]/.test(url) || url.slice(0, 2) === '//') { return ''; }
        const scheme = url.match(/^([a-z][a-z0-9+.\-]*):/i);
        if (!scheme) { return url; }
        const s = scheme[1].toLowerCase();
        if (s === 'http' || s === 'https') { return /^https?:\/\/[^/?#\s]+/i.test(url) ? url : ''; }
        if (s === 'mailto') { return /^mailto:[^\s<>"]+$/i.test(url) ? url : ''; }
        if (s === 'tel') { const tel = url.replace(/\s+/g, ''); return /^tel:[+0-9()\-.]{2,40}$/i.test(tel) ? tel : ''; }
        return '';
    }

    function safeSrc(value) {
        const url = String(value || '').trim();
        if (/^https:\/\/[^/?#\s"<>]+/i.test(url)) { return url; }
        return /^\/[^/\\]/.test(url) && !/[\s"<>]/.test(url) ? url : '';
    }

    function copy(from, to, doc) {
        Array.prototype.forEach.call(from.childNodes, function (node) {
            if (node.nodeType === 3) { to.appendChild(doc.createTextNode(node.nodeValue)); return; }
            if (node.nodeType !== 1) { return; }
            let tag = String(node.tagName).toUpperCase();
            if (DROP.test(tag) || tag.indexOf(':') !== -1) { return; }
            tag = RENAME[tag] || tag;
            // A Google Docs text arrives wrapped in <b style="font-weight:normal">, that is not bold.
            if (tag === 'B' && /font-weight\s*:\s*(normal|400)/i.test(node.getAttribute('style') || '')) { copy(node, to, doc); return; }
            if (ALLOWED[tag]) {
                const el = doc.createElement(tag);
                if (tag === 'A') {
                    const href = safeHref(node.getAttribute('href'));
                    if (!href) { copy(node, to, doc); return; }
                    el.setAttribute('href', href);
                    if (node.getAttribute('title')) { el.setAttribute('title', node.getAttribute('title').slice(0, 300)); }
                } else if (tag === 'IMG') {
                    const src = safeSrc(node.getAttribute('src'));
                    if (!src) { return; }
                    el.setAttribute('src', src);
                    el.setAttribute('alt', (node.getAttribute('alt') || '').slice(0, 500));
                } else {
                    ALLOWED[tag].forEach(function (name) {
                        const v = node.getAttribute(name);
                        if (v && /^[1-9][0-9]?$/.test(v) && v !== '1') { el.setAttribute(name, v); }
                    });
                }
                if (tag !== 'IMG' && tag !== 'BR' && tag !== 'HR') { copy(node, el, doc); }
                to.appendChild(el);
                return;
            }
            if (CONTAINER.test(tag)) {
                // A container of another site: a paragraph when it holds only text, otherwise its children take its place.
                const box = doc.createElement('div');
                copy(node, box, doc);
                const hasBlock = Array.prototype.some.call(box.children, function (c) { return BLOCK.test(c.tagName); });
                if (hasBlock || /^(LI|TD|TH|BLOCKQUOTE|P|H2|H3|H4|A|STRONG|B|EM|I|U|S|CODE|PRE|FIGCAPTION)$/.test(to.tagName)) {
                    while (box.firstChild) { to.appendChild(box.firstChild); }
                    if (!hasBlock && /^(LI|TD|TH|BLOCKQUOTE)$/.test(to.tagName)) { to.appendChild(doc.createElement('br')); }
                } else {
                    const p = doc.createElement('p');
                    while (box.firstChild) { p.appendChild(box.firstChild); }
                    to.appendChild(p);
                }
                return;
            }
            copy(node, to, doc);
        });
    }

    function blank(el) {
        return Array.prototype.every.call(el.childNodes, function (c) {
            return c.nodeType === 1 ? c.tagName === 'BR' : !/[^\s\u00a0]/.test(c.nodeValue || '');
        });
    }

    function dropEmpty(el) {
        Array.prototype.slice.call(el.children).forEach(function (c) {
            dropEmpty(c);
            if (!KEEP_EMPTY.test(c.tagName) && blank(c)) { c.remove(); }
        });
    }

    /** HTML through the white list: unknown tags unwrapped, scripts and handlers gone, loose text in paragraphs. */
    function clean(html) {
        const src = new DOMParser().parseFromString('<!doctype html><body>' + String(html || ''), 'text/html').body;
        const doc = document.implementation.createHTMLDocument('');
        const out = doc.createElement('div');
        copy(src, out, doc);
        // Text and inline tags at the top level go into paragraphs.
        let run = null;
        Array.prototype.slice.call(out.childNodes).forEach(function (node) {
            if (node.nodeType === 1 && BLOCK.test(node.tagName)) { run = null; return; }
            if (!run) {
                if (node.nodeType === 3 && !/[^\s\u00a0]/.test(node.nodeValue)) { node.remove(); return; }
                run = doc.createElement('p');
                out.insertBefore(run, node);
            }
            run.appendChild(node);
        });
        dropEmpty(out);
        return out.innerHTML.trim();
    }

    function escapeHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /** Plain text from the clipboard: paragraphs by empty lines, single line breaks kept. */
    function fromText(text) {
        return String(text || '').replace(/\r\n?/g, '\n').split(/\n[ \t]*\n\s*/).map(function (p) { return p.trim(); })
            .filter(Boolean).map(function (p) { return '<p>' + escapeHtml(p).replace(/\n/g, '<br>') + '</p>'; }).join('');
    }

    function textOf(html) {
        const d = new DOMParser().parseFromString('<body>' + String(html || ''), 'text/html').body;
        return (d.textContent || '').replace(/\s+/g, ' ').trim() + (d.querySelector('img') ? '[img]' : '');
    }

    const BUTTONS = [
        ['bold', 'Ж', 'Жирный (Ctrl+B)'],
        ['italic', 'К', 'Курсив (Ctrl+I)'],
        ['link', 'Ссылка', 'Ссылка: выделите слова и нажмите'],
        ['h2', 'H2', 'Заголовок раздела'],
        ['h3', 'H3', 'Подзаголовок'],
        ['ul', '• Список', 'Список'],
        ['ol', '1. Список', 'Нумерованный список'],
        ['quote', '« Цитата', 'Цитата']
    ];

    function blockOf(node, root) {
        for (let n = node; n && n !== root; n = n.parentNode) {
            if (n.nodeType === 1 && /^(P|H2|H3|H4|BLOCKQUOTE|LI|PRE|DIV)$/.test(n.tagName)) { return n; }
        }
        return null;
    }

    function closest(node, tag, root) {
        for (let n = node; n && n !== root; n = n.parentNode) {
            if (n.nodeType === 1 && n.tagName === tag) { return n; }
        }
        return null;
    }

    function selectionIn(el) {
        const sel = window.getSelection();
        return sel && sel.rangeCount && el.contains(sel.anchorNode) ? sel : null;
    }

    /** One button of the toolbar applied to the selection inside el. */
    function exec(cmd, el) {
        if (!selectionIn(el)) { el.focus(); }
        const sel = window.getSelection();
        const at = sel && sel.anchorNode;
        try { document.execCommand('styleWithCSS', false, false); } catch (e) { /* older engines */ }
        if (cmd === 'bold' || cmd === 'italic') {
            document.execCommand(cmd);
        } else if (cmd === 'ul' || cmd === 'ol') {
            document.execCommand(cmd === 'ul' ? 'insertUnorderedList' : 'insertOrderedList');
        } else if (cmd === 'h2' || cmd === 'h3' || cmd === 'quote') {
            const want = cmd === 'quote' ? 'BLOCKQUOTE' : cmd.toUpperCase();
            const block = at ? blockOf(at, el) : null;
            const inside = cmd === 'quote' ? !!(at && closest(at, 'BLOCKQUOTE', el)) : !!(block && block.tagName === want);
            document.execCommand('formatBlock', false, inside ? '<p>' : '<' + want.toLowerCase() + '>');
        } else if (cmd === 'link') {
            const a = at ? closest(at, 'A', el) : null;
            const range = sel && sel.rangeCount ? sel.getRangeAt(0).cloneRange() : null;
            const answer = window.prompt('Адрес ссылки: https://..., /страница, #якорь, mailto: или tel:. Пусто: убрать ссылку.', a ? a.getAttribute('href') : 'https://');
            if (answer === null) { return; }
            if (range) { sel.removeAllRanges(); sel.addRange(range); }
            const url = answer.trim();
            if (url === '' || url === 'https://') {
                if (a) {
                    const r = document.createRange();
                    r.selectNodeContents(a);
                    sel.removeAllRanges();
                    sel.addRange(r);
                }
                document.execCommand('unlink');
            } else if (!safeHref(url)) {
                window.alert('Такую ссылку поставить нельзя: нужен адрес вида https://..., /страница, #якорь, mailto: или tel:.');
                return;
            } else if (a) {
                a.setAttribute('href', safeHref(url));
            } else if (sel && sel.isCollapsed) {
                document.execCommand('insertHTML', false, '<a href="' + escapeHtml(safeHref(url)) + '">' + escapeHtml(url) + '</a>');
            } else {
                document.execCommand('createLink', false, safeHref(url));
            }
        }
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }

    /** The row of buttons. getTarget() names the editable box the buttons act on (one bar may serve several boxes). */
    function toolbar(doc, className, getTarget) {
        const bar = doc.createElement('div');
        bar.className = className;
        bar.setAttribute('role', 'toolbar');
        bar.setAttribute('aria-label', 'Оформление текста');
        BUTTONS.forEach(function (b) {
            const btn = doc.createElement('button');
            btn.type = 'button';
            btn.textContent = b[1];
            btn.title = b[2];
            btn.setAttribute('data-cmd', b[0]);
            // The selection must stay in the text while a button is pressed.
            btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const target = getTarget();
                if (target) { exec(b[0], target); }
            });
            bar.appendChild(btn);
        });
        return bar;
    }

    /** Makes a box editable: paragraphs on Enter, pasted HTML cleaned, no pictures dropped in as data. */
    function attach(el) {
        el.setAttribute('contenteditable', 'true');
        if (!el.innerHTML.trim()) { el.innerHTML = '<p><br></p>'; }
        el.addEventListener('focus', function () {
            try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { /* older engines */ }
        });
        el.addEventListener('paste', function (e) {
            const data = e.clipboardData || window.clipboardData;
            if (!data) { return; }
            e.preventDefault();
            const html = data.getData('text/html');
            const out = html ? clean(html) : fromText(data.getData('text/plain'));
            if (out) { document.execCommand('insertHTML', false, out); }
        });
        el.addEventListener('drop', function (e) {
            if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') !== -1) { e.preventDefault(); }
        });
        return { value: function () { return clean(el.innerHTML); } };
    }

    window.AgeRich = { clean: clean, attach: attach, toolbar: toolbar, exec: exec, textOf: textOf, safeHref: safeHref, buttons: BUTTONS };
}());
