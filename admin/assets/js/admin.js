// Admin behaviour that needs a script. Everything works without it; this only adds the manual
// light and dark switch, moves focus to a form's list of problems, and warns before a writer
// leaves a form with unsaved changes.
(function () {
    'use strict';

    var summary = document.getElementById('lf-error-summary');

    if (summary) {
        summary.focus();
    }

    /* A writer's form asks before the page is left with changes that were not saved. The chapter text is
       left out of the comparison because the editor keeps track of it itself. */
    function formState(form) {
        var parts = [];

        Array.prototype.forEach.call(form.elements, function (field) {
            var skipped = !field.name || field.disabled || field.name === 'csrf_token'
                || field.type === 'submit' || field.type === 'button'
                || field.hasAttribute('data-lf-textarea') || field.hasAttribute('data-lf-format-field');

            if (skipped || ((field.type === 'checkbox' || field.type === 'radio') && !field.checked)) {
                return;
            }

            parts.push(field.name + '=' + field.value);
        });

        return parts.join('\u0001');
    }

    Array.prototype.forEach.call(document.querySelectorAll('form[data-lf-guard]'), function (form) {
        var original = formState(form);
        var sending = false;

        form.addEventListener('submit', function () {
            sending = true;
        });

        window.addEventListener('beforeunload', function (event) {
            if (!sending && formState(form) !== original) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
    });

    var toggle = document.querySelector('[data-lf-theme-toggle]');

    if (!toggle) {
        return;
    }

    function isDark() {
        var chosen = document.documentElement.getAttribute('data-theme');

        if (chosen === 'dark' || chosen === 'light') {
            return chosen === 'dark';
        }

        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    }

    function show() {
        var dark = isDark();

        toggle.textContent = dark ? 'Light mode' : 'Dark mode';
        toggle.setAttribute('aria-pressed', dark ? 'true' : 'false');
    }

    toggle.hidden = false;
    show();

    toggle.addEventListener('click', function () {
        var next = isDark() ? 'light' : 'dark';

        document.documentElement.setAttribute('data-theme', next);

        try {
            window.localStorage.setItem('lf-admin-theme', next);
        } catch (error) {
            // The choice still applies to this page even if it cannot be remembered.
        }

        show();
    });
}());
