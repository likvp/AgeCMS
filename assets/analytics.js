// Yandex Metrika starter. The counter number and options come from the data attributes of this script tag
// (set in the admin: Settings, Analytics), so the page needs no inline script and the Content-Security-Policy stays strict.
(function () {
    'use strict';

    var tag = document.currentScript || document.querySelector('script[data-ym]');
    if (!tag || !document.body || document.body.classList.contains('is-editing')) {
        return;
    }
    var id = parseInt(tag.getAttribute('data-ym'), 10);
    if (!id) {
        return;
    }
    var picked = (tag.getAttribute('data-ym-options') || '').split(',');
    var options = { clickmap: false, trackLinks: false, accurateTrackBounce: false, webvisor: false, trackHash: false };
    picked.forEach(function (name) {
        if (Object.prototype.hasOwnProperty.call(options, name)) {
            options[name] = true;
        }
    });

    (function (m, e, t, r, i, k, a) {
        m[i] = m[i] || function () { (m[i].a = m[i].a || []).push(arguments); };
        m[i].l = 1 * new Date();
        for (var j = 0; j < e.scripts.length; j++) { if (e.scripts[j].src === r) { return; } }
        k = e.createElement(t); a = e.getElementsByTagName(t)[0];
        k.async = 1; k.src = r; a.parentNode.insertBefore(k, a);
    })(window, document, 'script', 'https://mc.yandex.ru/metrika/tag.js', 'ym');

    window.ym(id, 'init', options);
})();
