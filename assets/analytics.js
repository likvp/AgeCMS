// Yandex Metrika starter. The counter number and options come from the data attributes of this script tag
// (set in the admin: Settings, Analytics), so the page needs no inline script and the Content-Security-Policy stays strict.
// The queue of the counter (ym) is ready at once, so goals reached before the script arrives are not lost; the heavy script of
// Yandex is fetched a few seconds after the page is loaded (data-ym-load="now": at once), so it does not compete with the main
// picture and the first taps.
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
    var URL = 'https://mc.yandex.ru/metrika/tag.js';

    window.ym = window.ym || function () { (window.ym.a = window.ym.a || []).push(arguments); };
    window.ym.l = 1 * new Date();
    window.ym(id, 'init', options);

    var loaded = false;
    function load() {
        if (loaded) { return; }
        loaded = true;
        for (var j = 0; j < document.scripts.length; j++) { if (document.scripts[j].src === URL) { return; } }
        var s = document.createElement('script');
        s.async = true;
        s.src = URL;
        document.head.appendChild(s);
    }

    if (tag.getAttribute('data-ym-load') === 'now') {
        load();
        return;
    }
    function later() {
        setTimeout(function () {
            if ('requestIdleCallback' in window) { window.requestIdleCallback(load, { timeout: 1500 }); } else { load(); }
        }, 2500);
    }
    if (document.readyState === 'complete') { later(); } else { window.addEventListener('load', later); }
}());
