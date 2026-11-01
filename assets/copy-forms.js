/*
 * Forms of copied pages (CopyPage): the importer marks a form that sent its data to another service with data-ag-form.
 * Here such a form sends its fields to this site as a request (/_/lead), the same way the forms of the theme do.
 * Name, phone and message go to their own fields, everything else is added to the message as "label: value".
 */
(function () {
  'use strict';
  var token = '';
  var tokenAt = 0;

  function loadToken() {
    return fetch('/_/token', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { token = j.token || ''; tokenAt = Date.now(); })
      .catch(function () {});
  }

  function labelOf(el) {
    var t = el.getAttribute('aria-label') || el.getAttribute('placeholder') || el.getAttribute('data-label') || '';
    if (!t && el.id) {
      var l = document.querySelector('label[for="' + el.id.replace(/"/g, '') + '"]');
      if (l) t = l.textContent;
    }
    if (!t) {
      var wrap = el.closest ? el.closest('label') : null;
      if (wrap) t = wrap.textContent;
    }
    if (!t) {
      var prev = el.previousElementSibling;
      if (prev && /^(label|div|span)$/i.test(prev.tagName) && prev.textContent.length < 60) t = prev.textContent;
    }
    return (t || el.getAttribute('name') || '').replace(/\s+/g, ' ').trim().slice(0, 60);
  }

  function collect(form) {
    var out = { name: '', phone: '', message: '', extra: [] };
    Array.prototype.forEach.call(form.elements, function (el) {
      var name = el.name || '';
      var type = (el.type || '').toLowerCase();
      if (!name || el.disabled || /^(submit|button|reset|image|file|password)$/.test(type) || /^(tildaspec|form-spec|_wp|_wpcf7|g-recaptcha|website_hp|token)/i.test(name)) return;
      if ((type === 'checkbox' || type === 'radio') && !el.checked) return;
      var v = (el.value || '').trim();
      if (v === '') return;
      if (type === 'hidden') return;
      if (!out.phone && (type === 'tel' || /(phone|tel|телефон|тел)/i.test(name))) { out.phone = v; return; }
      if (!out.name && (/(^|[^a-z])(name|fio|fullname|имя|фио)/i.test(name))) { out.name = v; return; }
      if (!out.message && (el.tagName === 'TEXTAREA' || /(message|comment|text|msg|сообщ|коммент)/i.test(name))) { out.message = v; return; }
      out.extra.push(labelOf(el) + ': ' + v);
    });
    var parts = [];
    if (out.message) parts.push(out.message);
    parts = parts.concat(out.extra);
    var msg = parts.join('\n');
    if (!out.phone && !/^[0-9+()\-\s]{5,30}$/.test(out.phone)) {
      // A phone written in words or with other signs goes into the message, the field stays empty.
      out.phone = '';
    }
    if (out.phone && !/^[0-9+()\-\s]{5,30}$/.test(out.phone)) {
      msg = 'Телефон: ' + out.phone + (msg ? '\n' + msg : '');
      out.phone = '';
    }
    return { name: out.name.slice(0, 100), phone: out.phone, message: msg.slice(0, 2000) };
  }

  function notice(form, text, ok) {
    var box = form.nextElementSibling && form.nextElementSibling.getAttribute && form.nextElementSibling.hasAttribute('data-ag-form-msg') ? form.nextElementSibling : null;
    if (!box) {
      box = document.createElement('div');
      box.setAttribute('data-ag-form-msg', '');
      box.setAttribute('role', 'status');
      form.parentNode.insertBefore(box, form.nextSibling);
    }
    box.textContent = text;
    box.style.cssText = 'margin:12px 0;padding:12px 16px;border-radius:6px;font:16px/1.4 sans-serif;color:#fff;background:' + (ok ? '#2e7d32' : '#c62828');
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.hasAttribute || !form.hasAttribute('data-ag-form')) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    if (form.getAttribute('data-ag-busy') === '1') return;
    if (form.checkValidity && !form.checkValidity()) {
      if (form.reportValidity) form.reportValidity();
      return;
    }
    var data = collect(form);
    if (data.phone === '' && data.message === '') {
      notice(form, 'Заполните поля формы.', false);
      return;
    }
    form.setAttribute('data-ag-busy', '1');
    var wait = Math.max(0, 2300 - (Date.now() - tokenAt));
    var ready = token === '' ? loadToken().then(function () { return new Promise(function (r) { setTimeout(r, 2300); }); }) : new Promise(function (r) { setTimeout(r, wait); });
    ready.then(function () {
      var path = location.pathname.replace(/^\/+|\/+$/g, '');
      return fetch('/_/lead', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: data.name, phone: data.phone, message: data.message, page: path === '' ? 'index' : path, token: token, website_hp: '' }),
      });
    }).then(function (r) { return r.json().catch(function () { return { ok: false }; }); }).then(function (j) {
      form.removeAttribute('data-ag-busy');
      if (j && j.ok) {
        notice(form, 'Спасибо! Заявка отправлена, мы свяжемся с вами.', true);
        form.reset();
      } else {
        notice(form, (j && j.error) || 'Не удалось отправить. Попробуйте ещё раз.', false);
      }
      token = '';
      loadToken();
    }).catch(function () {
      form.removeAttribute('data-ag-busy');
      notice(form, 'Не удалось отправить. Проверьте соединение и попробуйте ещё раз.', false);
    });
  }, true);

  // Tilda and similar forms submit by a click on their own button: the browser still raises "submit" for a real submit button.
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', loadToken); else loadToken();
})();
