(function () {
    'use strict';

    var csrf = (document.querySelector('meta[name="ag-csrf"]') || {}).content || '';
    var statusEl = document.getElementById('ag-status');

    function say(text) {
        if (statusEl) { statusEl.textContent = text; }
    }

    function save(el, value) {
        el.classList.remove('ag-error');
        el.classList.add('ag-saving');
        say('Сохранение...');
        return fetch('/admin/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            credentials: 'same-origin',
            body: JSON.stringify({
                action: 'update_block_field',
                slug: el.dataset.agSlug,
                block_id: el.dataset.agBlock,
                field_path: el.dataset.agField,
                value: value
            })
        }).then(function (r) { return r.json(); }).then(function (d) {
            el.classList.remove('ag-saving');
            if (d.ok) {
                el.dataset.agSaved = value;
                say(d.result && d.result.changed ? 'Сохранено, ревизия #' + d.result.revision_id : 'Без изменений');
            } else {
                el.classList.add('ag-error');
                say('Ошибка: ' + (d.error || 'не сохранилось'));
            }
        }).catch(function () {
            el.classList.remove('ag-saving');
            el.classList.add('ag-error');
            say('Нет связи с сервером');
        });
    }

    document.querySelectorAll('[data-ag-field]').forEach(function (el) {
        var multi = el.hasAttribute('data-ag-multi');
        el.setAttribute('contenteditable', 'true');
        el.setAttribute('spellcheck', 'true');
        el.dataset.agSaved = el.innerText.trim();
        var timer = null;

        el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !multi) { e.preventDefault(); el.blur(); }
            if (e.key === 'Escape') { el.innerText = el.dataset.agSaved; el.blur(); }
        });
        // Plain text only: pasted HTML would never pass the schema anyway.
        el.addEventListener('paste', function (e) {
            e.preventDefault();
            var text = (e.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, multi ? text : text.replace(/\s*\n\s*/g, ' '));
        });
        el.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                var value = el.innerText.replace(/ /g, ' ').trim();
                if (value !== el.dataset.agSaved) { save(el, value); }
            }, 800);
        });
        el.addEventListener('blur', function () {
            clearTimeout(timer);
            var value = el.innerText.replace(/ /g, ' ').trim();
            if (value !== el.dataset.agSaved) { save(el, value); }
        });
    });
}());
