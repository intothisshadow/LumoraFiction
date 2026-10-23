// Shows a theme's details in a dialog. Without this script (or in a browser with no <dialog>) the details
// stay on the page as ordinary sections, and the Details links jump to them.
(function () {
    'use strict';

    var dialog = document.querySelector('[data-lf-theme-dialog]');
    var area = document.querySelector('[data-lf-details-area]');

    if (!dialog || !area || typeof dialog.showModal !== 'function') {
        return;
    }

    var current = null;
    var opener = null;

    area.classList.add('lf-theme-details-area--dialog');

    Array.prototype.forEach.call(area.querySelectorAll('[data-lf-dialog-close]'), function (button) {
        button.hidden = false;
        button.addEventListener('click', function () {
            dialog.close();
        });
    });

    function putBack() {
        if (!current) {
            return;
        }

        // The section goes home (at the end of the area), so a second visit finds it again and no id is ever duplicated.
        area.appendChild(current);
        current = null;
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-lf-theme-details]'), function (link) {
        link.addEventListener('click', function (event) {
            var section = document.getElementById(link.getAttribute('data-lf-theme-details'));

            if (!section) {
                return;
            }

            event.preventDefault();
            putBack();
            current = section;
            opener = link;
            dialog.setAttribute('aria-labelledby', section.id + '-title');
            dialog.appendChild(section);
            dialog.showModal();
        });
    });

    // A click on the dimmed area outside the box closes it, as Escape and the Close button do.
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            dialog.close();
        }
    });

    dialog.addEventListener('close', function () {
        putBack();
        dialog.removeAttribute('aria-labelledby');

        if (opener) {
            opener.focus();
            opener = null;
        }
    });
}());
