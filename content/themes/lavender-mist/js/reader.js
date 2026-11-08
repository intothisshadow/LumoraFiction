/*
 * Lavender Mist: remembers the reader's colour scheme, text size and reading width in this browser.
 * Nothing is sent anywhere. Without this script the page simply uses its defaults.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'lf-reader';
    var CHOICES = {
        scheme: ['system', 'light', 'dark'],
        size: ['small', 'default', 'large', 'xlarge'],
        width: ['narrow', 'default', 'wide']
    };
    // The attribute each preference sets on the root element; "system" and "default" remove it.
    var ATTRIBUTES = {scheme: 'data-lf-scheme', size: 'data-lf-size', width: 'data-lf-width'};
    var NEUTRAL = {scheme: 'system', size: 'default', width: 'default'};

    var root = document.documentElement;

    function load() {
        var saved = {};

        try {
            saved = JSON.parse(window.localStorage.getItem(STORAGE_KEY)) || {};
        } catch (error) {
            saved = {};
        }

        var prefs = {};

        Object.keys(CHOICES).forEach(function (name) {
            prefs[name] = CHOICES[name].indexOf(saved[name]) !== -1 ? saved[name] : NEUTRAL[name];
        });

        return prefs;
    }

    function save(prefs) {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
        } catch (error) {
            // Storage can be blocked; the choice then lasts for this page only.
        }
    }

    function apply(prefs) {
        Object.keys(ATTRIBUTES).forEach(function (name) {
            if (prefs[name] === NEUTRAL[name]) {
                root.removeAttribute(ATTRIBUTES[name]);
            } else {
                root.setAttribute(ATTRIBUTES[name], prefs[name]);
            }
        });
    }

    // Signed-in readers: core writes the account's saved choices and where to send changes into the head.
    function meta(name) {
        var element = document.querySelector('meta[name="' + name + '"]');

        return element ? element.getAttribute('content') : null;
    }

    var accountSave = meta('lf-reader-save');
    var accountToken = meta('lf-reader-token');

    function accountPreferences() {
        var saved = {};

        try {
            saved = JSON.parse(meta('lf-reader-prefs')) || {};
        } catch (error) {
            saved = {};
        }

        return saved;
    }

    var saveTimer = null;
    var saving = false;
    var saveWaiting = false;

    // A button invites several quick presses, and each save token works once. So saves wait for a short pause, only one
    // runs at a time, and the one that follows carries whatever the choices are by then.
    function sendToAccount() {
        if (!accountSave || !accountToken || !window.fetch) {
            return;
        }

        saveWaiting = true;
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(flushToAccount, 400);
    }

    function flushToAccount() {
        window.clearTimeout(saveTimer);

        if (!saveWaiting || saving) {
            return;
        }

        saveWaiting = false;
        saving = true;

        var body = new URLSearchParams();

        body.set('csrf_token', accountToken);
        body.set('script', '1');
        Object.keys(CHOICES).forEach(function (name) {
            body.set(name, preferences[name]);
        });

        window.fetch(accountSave, {method: 'POST', body: body, credentials: 'same-origin', keepalive: true})
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (result) {
                // Each token works once, so the reply carries the one for the next change.
                if (result && result.token) {
                    accountToken = result.token;
                }
            })
            .catch(function () {
                // The choice still applies in this browser.
            })
            .then(function () {
                saving = false;

                if (saveWaiting) {
                    flushToAccount();
                }
            });
    }

    // Leaving the page must not lose a choice that is still waiting for its pause to end.
    window.addEventListener('pagehide', flushToAccount);

    var preferences = load();

    // The account's choices win over this browser's, so the same settings follow a reader to another device.
    (function mergeAccount() {
        if (!accountSave) {
            return;
        }

        var account = accountPreferences();

        Object.keys(CHOICES).forEach(function (name) {
            if (CHOICES[name].indexOf(account[name]) !== -1) {
                preferences[name] = account[name];
            }
        });
        save(preferences);
    })();

    // Runs in the head, before the page is drawn, so a saved choice never flashes the wrong colours.
    root.classList.add('lf-js');
    apply(preferences);

    // A button that steps through the choices in the order its markup lists them (data-lf-cycle), for example the colour
    // button. Only values the script already knows are ever used, so a damaged attribute cannot store anything else.
    function setUpCycleButton(button) {
        var name = button.getAttribute('data-lf-pref');

        if (!CHOICES.hasOwnProperty(name)) {
            return;
        }

        var order = (button.getAttribute('data-lf-cycle') || '').split(/\s+/).filter(function (value) {
            return CHOICES[name].indexOf(value) !== -1;
        });

        if (order.length < 2) {
            order = CHOICES[name].slice();
        }

        function text(prefix, value) {
            return button.getAttribute('data-lf-' + prefix + '-' + value) || value;
        }

        function nextOf(current) {
            var index = order.indexOf(current);

            return order[(index + 1) % order.length];
        }

        // The accessible name says what the button does and the mode it is in, so a screen reader or keyboard user always knows what the next press will do. It is not a live region: nothing is announced over the reading.
        function paint() {
            var current = CHOICES[name].indexOf(preferences[name]) !== -1 ? preferences[name] : NEUTRAL[name];
            var label = button.querySelector('[data-lf-scheme-label]');

            button.setAttribute('data-lf-mode', current);
            button.setAttribute('aria-label', (button.getAttribute('data-lf-name') || name) + ': ' + text('state', current) + '. ' + text('press', nextOf(current)));

            if (label) {
                label.textContent = text('label', current);
            }
        }

        button.addEventListener('click', function () {
            preferences[name] = nextOf(preferences[name]);
            apply(preferences);
            save(preferences);
            sendToAccount();
            paint();
        });

        paint();
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('button[data-lf-pref]'), setUpCycleButton);

        var selects = document.querySelectorAll('select[data-lf-pref]');

        Array.prototype.forEach.call(selects, function (select) {
            var name = select.getAttribute('data-lf-pref');

            if (!CHOICES.hasOwnProperty(name)) {
                return;
            }

            select.value = preferences[name];
            select.addEventListener('change', function () {
                if (CHOICES[name].indexOf(select.value) === -1) {
                    return;
                }

                preferences[name] = select.value;
                apply(preferences);
                save(preferences);
                sendToAccount();
            });
        });
    });
})();
