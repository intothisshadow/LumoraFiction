// Carries a running job on to its next step. Each step is a request of its own, so a slow host never has to finish
// everything in one go. The answer redraws the page in place; anything unexpected (a network error, a timeout, an answer
// that is not JSON) falls back to submitting the form, so the page the server draws shows what happened and nothing is lost.
// Without this script (or with it blocked) the Continue button does the same by hand.
(function () {
    'use strict';

    var form = document.querySelector('form[data-lf-autoadvance="1"]');

    if (!form) {
        return;
    }

    var button = form.querySelector('button[type="submit"]');
    var tokenField = form.querySelector('input[name="csrf_token"]');
    var stepsList = document.querySelector('[data-lf-run-steps]');
    var logList = document.querySelector('[data-lf-run-log]');
    var statusLine = document.querySelector('[data-lf-run-status]');
    var messageLine = document.querySelector('[data-lf-run-message]');
    var expected = form.getAttribute('data-lf-status') || 'running';
    var STEP_TIMEOUT = 120000;
    var PAUSE = 300;

    function submitNormally() {
        if (button) {
            button.disabled = true;
            button.textContent = 'Working…';
        }

        form.submit();
    }

    // Only a path on this site is followed, whatever the answer says.
    function isLocalPath(path) {
        return typeof path === 'string' && path.charAt(0) === '/' && path.charAt(1) !== '/';
    }

    function say(text) {
        if (!statusLine) {
            return;
        }

        statusLine.textContent = text;
        statusLine.hidden = text === '';
    }

    function drawSteps(steps) {
        if (!stepsList || !Array.isArray(steps)) {
            return;
        }

        stepsList.textContent = '';

        steps.forEach(function (step) {
            var item = document.createElement('li');
            var state = document.createElement('span');

            item.className = 'lf-update-steps__item lf-update-steps__item--' + step.state;
            item.appendChild(document.createTextNode(step.label + ' '));

            if (step.state === 'current') {
                item.setAttribute('aria-current', 'step');
            }

            state.className = 'lf-update-steps__state';
            state.textContent = step.state === 'done' ? '(done)' : (step.state === 'current' ? '(working)' : '');
            item.appendChild(state);
            stepsList.appendChild(item);
        });
    }

    function drawLog(lines) {
        if (!logList || !Array.isArray(lines)) {
            return;
        }

        logList.textContent = '';
        logList.hidden = lines.length === 0;

        lines.forEach(function (line) {
            var item = document.createElement('li');

            if (!line.ok) {
                item.className = 'lf-update-log-list__item--problem';
            }

            item.textContent = line.text;
            logList.appendChild(item);
        });
    }

    function step() {
        var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
        var timer = window.setTimeout(function () {
            if (controller) {
                controller.abort();
            }
        }, STEP_TIMEOUT);

        say('Still working. This can take a little while on a slow host.');

        window.fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'csrf_token=' + encodeURIComponent(tokenField ? tokenField.value : ''),
            signal: controller ? controller.signal : undefined
        }).then(function (response) {
            if ((response.headers.get('Content-Type') || '').indexOf('application/json') !== 0) {
                throw new Error('Not a JSON answer.');
            }

            return response.json();
        }).then(function (data) {
            window.clearTimeout(timer);

            if (data && isLocalPath(data.redirect)) {
                window.location.assign(data.redirect);

                return;
            }

            // A change of kind (a step that stopped, or a rollback that began) needs the notice and buttons the server draws.
            if (!data || data.status !== expected || typeof data.token !== 'string' || data.token === '') {
                window.location.reload();

                return;
            }

            tokenField.value = data.token;
            drawSteps(data.steps);
            drawLog(data.log);

            // A job with no step list, such as an import, reports its progress as one sentence.
            if (messageLine && typeof data.message === 'string') {
                messageLine.textContent = data.message;
            }

            window.setTimeout(step, PAUSE);
        }).catch(function () {
            window.clearTimeout(timer);
            submitNormally();
        });
    }

    if (typeof window.fetch !== 'function' || !tokenField) {
        // A short pause lets the page be read, and keeps a failing step from becoming a tight loop.
        window.setTimeout(submitNormally, 800);

        return;
    }

    if (button) {
        button.disabled = true;
    }

    window.setTimeout(step, 800);
}());
