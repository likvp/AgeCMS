// Notice about cookies (Renderer::withCookieNotice). It sits hidden in the page; whoever has not closed it yet sees it.
// Closing is remembered in localStorage and, where storage is off, in a cookie for a year.
// Its styles (cookie.css) are fetched here and only for a visitor who still has to see the notice, so they never block the
// first paint of the page; the notice appears when they are in.
(function () {
    'use strict';
    var me = document.currentScript;
    var box = document.querySelector('[data-ag-cookie]');
    if (!box) { return; }
    var KEY = 'ag-cookie-ok';
    function closed() {
        try { if (window.localStorage.getItem(KEY) === '1') { return true; } } catch (e) { /* storage is off */ }
        return document.cookie.split('; ').indexOf(KEY + '=1') !== -1;
    }
    if (closed()) { return; }
    var ok = box.querySelector('[data-ag-cookie-ok]');
    function show() {
        box.hidden = false;
        if (!ok) { return; }
        ok.addEventListener('click', function () {
            try { window.localStorage.setItem(KEY, '1'); } catch (e) { /* storage is off */ }
            document.cookie = KEY + '=1; max-age=31536000; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
            box.hidden = true;
        });
    }
    if (!me || !me.src || document.querySelector('link[data-ag-cookie-css]')) { show(); return; }
    var css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = me.src.replace(/cookie\.js(\?|$)/, 'cookie.css$1');
    css.setAttribute('data-ag-cookie-css', '');
    css.onload = show;
    css.onerror = show;
    document.head.appendChild(css);
}());
