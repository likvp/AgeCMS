(function () {
    'use strict';

    // Anonymous page-view beacon: no cookies, honours Do Not Track, skipped in edit mode.
    if (!document.body.classList.contains('is-editing') && navigator.doNotTrack !== '1' && navigator.sendBeacon) {
        navigator.sendBeacon('/_/hit', JSON.stringify({ p: location.pathname }));
    }

    // Lead forms. The page itself is a static file, so the anti-spam token is fetched on load.
    document.querySelectorAll('form[data-lead]').forEach(function (form) {
        var status = form.querySelector('.form-status');
        var tokenField = form.querySelector('input[name="token"]');
        fetch('/_/token', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) { tokenField.value = d.token; })
            .catch(function () {});

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            status.className = 'form-status';
            status.textContent = '';
            var body = {};
            new FormData(form).forEach(function (value, key) { body[key] = value; });
            body.page = form.dataset.page || '';
            fetch('/_/lead', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
              .then(function (res) {
                  if (res.ok) {
                      form.reset();
                      status.textContent = form.dataset.success;
                      fetch('/_/token', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) { tokenField.value = d.token; });
                  } else {
                      status.className = 'form-status error';
                      status.textContent = res.data.error || 'Не удалось отправить, попробуйте позже.';
                  }
              })
              .catch(function () {
                  status.className = 'form-status error';
                  status.textContent = 'Нет связи, попробуйте позже.';
              });
        });
    });
}());
