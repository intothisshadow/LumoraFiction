// Carries a running update on to its next step. Each step is a request of its own, so a slow host never has to finish
// everything in one go. Without this script (or with it blocked) the Continue button does the same by hand.
(function () {
    'use strict';

    var form = document.querySelector('form[data-lf-autoadvance="1"]');

    if (!form) {
        return;
    }

    var button = form.querySelector('button[type="submit"]');

    // A short pause lets the page be read, and keeps a failing step from becoming a tight loop.
    window.setTimeout(function () {
        if (button) {
            button.disabled = true;
            button.textContent = 'Working…';
        }

        form.submit();
    }, 800);
}());
