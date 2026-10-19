/*
 * Storybook: remembers the reader's colour scheme, text size and reading width in this browser.
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

    function sendToAccount(prefs) {
        if (!accountSave || !accountToken || !window.fetch) {
            return;
        }

        var body = new URLSearchParams();

        body.set('csrf_token', accountToken);
        body.set('script', '1');
        Object.keys(CHOICES).forEach(function (name) {
            body.set(name, prefs[name]);
        });

        window.fetch(accountSave, {method: 'POST', body: body, credentials: 'same-origin'})
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
            });
    }

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

    document.addEventListener('DOMContentLoaded', function () {
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
                sendToAccount(preferences);
            });
        });
    });
})();
