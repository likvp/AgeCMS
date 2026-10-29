// Notice about cookies (Renderer::withCookieNotice). It sits hidden in the page; whoever has not closed it yet sees it.
// Closing is remembered in localStorage and, where storage is off, in a cookie for a year.
(function () {
    'use strict';
    var box = document.querySelector('[data-ag-cookie]');
    if (!box) { return; }
    var KEY = 'ag-cookie-ok';
    function closed() {
        try { if (window.localStorage.getItem(KEY) === '1') { return true; } } catch (e) { /* storage is off */ }
        return document.cookie.split('; ').indexOf(KEY + '=1') !== -1;
    }
    if (closed()) { return; }
    box.hidden = false;
    var ok = box.querySelector('[data-ag-cookie-ok]');
    if (!ok) { return; }
    ok.addEventListener('click', function () {
        try { window.localStorage.setItem(KEY, '1'); } catch (e) { /* storage is off */ }
        document.cookie = KEY + '=1; max-age=31536000; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
        box.hidden = true;
    });
}());
