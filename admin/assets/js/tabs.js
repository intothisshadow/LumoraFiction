// Tabs: switches panels in place and keeps the address in step. Without this script each tab is a link that
// reloads the screen with ?tab=, so nothing depends on it.
(function () {
    'use strict';

    Array.prototype.forEach.call(document.querySelectorAll('[data-lf-tabs]'), function (root) {
        var tabs = Array.prototype.slice.call(root.querySelectorAll('[role="tab"]'));

        if (tabs.length < 2) {
            return;
        }

        function panelFor(tab) {
            return document.getElementById(tab.getAttribute('aria-controls'));
        }

        function select(tab, focus) {
            tabs.forEach(function (other) {
                var on = other === tab;
                var panel = panelFor(other);

                other.setAttribute('aria-selected', on ? 'true' : 'false');
                other.classList.toggle('is-selected', on);
                other.tabIndex = on ? 0 : -1;

                if (panel) {
                    panel.hidden = !on;
                }
            });

            if (focus) {
                tab.focus();
            }
        }

        // Only one tab is in the tab order, as arrow keys move between the rest.
        tabs.forEach(function (tab) {
            tab.tabIndex = tab.getAttribute('aria-selected') === 'true' ? 0 : -1;

            tab.addEventListener('click', function (event) {
                if (event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) {
                    return;
                }

                event.preventDefault();
                select(tab, false);

                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', tab.getAttribute('href'));
                }

                // The settings form below the tabs posts back to the tab that is open.
                Array.prototype.forEach.call(document.querySelectorAll('input[data-lf-tab-field]'), function (field) {
                    field.value = tab.getAttribute('data-lf-tab');
                });
            });

            tab.addEventListener('keydown', function (event) {
                var index = tabs.indexOf(tab);
                var next = null;

                if (event.key === 'ArrowRight') {
                    next = tabs[(index + 1) % tabs.length];
                } else if (event.key === 'ArrowLeft') {
                    next = tabs[(index - 1 + tabs.length) % tabs.length];
                } else if (event.key === 'Home') {
                    next = tabs[0];
                } else if (event.key === 'End') {
                    next = tabs[tabs.length - 1];
                }

                if (next) {
                    event.preventDefault();
                    select(next, true);
                    next.click();
                }
            });
        });
    });
}());
