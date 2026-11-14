// Carries an import on by itself: a moment after the page loads it sends the "Carry on" form, which writes the next few stories
// and comes back here (or to the report when everything is written). Without this script the button does the same by hand.
(function () {
    'use strict';

    var form = document.querySelector('form[data-lf-autoadvance="1"]');

    if (!form) {
        return;
    }

    var button = form.querySelector('button');

    window.setTimeout(function () {
        if (button) {
            button.disabled = true;
            button.textContent = 'Working…';
        }

        form.submit();
    }, 600);
}());
