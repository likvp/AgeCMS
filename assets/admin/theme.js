// Applies the saved theme before first paint. "auto" follows the system setting.
(function () {
    try {
        var t = localStorage.getItem('ag-theme');
        if (t === 'light' || t === 'dark') { document.documentElement.setAttribute('data-theme', t); }
    } catch (e) { /* storage may be blocked */ }
}());
