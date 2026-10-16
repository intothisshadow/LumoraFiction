// Admin behaviour that needs a script. Everything works without it; this only adds the manual
// light and dark switch and moves focus to a form's list of problems.
(function () {
    'use strict';

    var summary = document.getElementById('lf-error-summary');

    if (summary) {
        summary.focus();
    }

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
