// Checks, from the visitor's browser, that clean web addresses reach the application. The check is
// advisory: it only reveals a warning, and the installer works whether or not it succeeds.
(function () {
    'use strict';

    var warning = document.getElementById('rewrite-warning');
    var summary = document.getElementById('error-summary');

    if (summary) {
        summary.focus();
    }

    if (!warning || !window.fetch) {
        return;
    }

    fetch(warning.getAttribute('data-url'), { cache: 'no-store', credentials: 'omit' })
        .then(function (response) { return response.text(); })
        .then(function (text) {
            if (text.trim() !== 'lumora-fiction-rewrite-ok') {
                warning.hidden = false;
            }
        })
        .catch(function () {
            warning.hidden = false;
        });
}());
