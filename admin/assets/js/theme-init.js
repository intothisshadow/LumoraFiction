// Applies a saved light or dark choice before the page is painted, so there is no flash of the wrong
// colours. Without a saved choice the stylesheet follows the system setting.
(function () {
    'use strict';

    try {
        var saved = window.localStorage.getItem('lf-admin-theme');

        if (saved === 'dark' || saved === 'light') {
            document.documentElement.setAttribute('data-theme', saved);
        }
    } catch (error) {
        // Storage can be blocked; the system setting applies instead.
    }
}());
