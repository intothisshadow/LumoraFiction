/*
 * Remembers how far down a chapter a signed-in reader got and offers to take them back. The place is a whole number
 * from 0 to 1000, a share of the chapter text, so it survives a change of window width or text size. Without this
 * script nothing is saved and the resume button stays hidden.
 */
(function () {
    'use strict';

    var script = document.querySelector('script[data-lf-reading-position]');
    var text = document.querySelector('.lf-chapter__text');

    if (!script || !text || !window.fetch) {
        return;
    }

    var saveUrl = script.getAttribute('data-save');
    var token = script.getAttribute('data-token');
    var storyId = script.getAttribute('data-story');
    var chapterId = script.getAttribute('data-chapter');
    var saved = parseInt(script.getAttribute('data-position'), 10) || 0;
    var SAVE_EVERY = 5000;

    if (!saveUrl || !token || !storyId || !chapterId) {
        return;
    }

    function textTop() {
        return text.getBoundingClientRect().top + window.pageYOffset;
    }

    // A chapter that fits on the screen has no place worth keeping.
    function scrolls() {
        return text.offsetHeight > window.innerHeight;
    }

    function share() {
        var value = (window.pageYOffset - textTop()) / text.offsetHeight;

        return Math.max(0, Math.min(1000, Math.round(value * 1000)));
    }

    var lastSent = saved;
    var inFlight = false;
    var timer = null;

    // The first screen is never saved: a reader who opens the chapter and leaves again must not wipe the place the
    // resume button is still offering.
    function inFirstScreen() {
        return window.pageYOffset < textTop() + window.innerHeight;
    }

    function send(keepalive) {
        var position = share();

        if (!scrolls() || inFirstScreen() || position === lastSent) {
            return;
        }

        // Each token works once and the reply carries the next, so only one request is open at a time.
        if (inFlight && !keepalive) {
            schedule();

            return;
        }

        var body = new URLSearchParams();

        body.set('csrf_token', token);
        body.set('story_id', storyId);
        body.set('chapter_id', chapterId);
        body.set('position', String(position));
        inFlight = true;
        lastSent = position;

        window.fetch(saveUrl, {method: 'POST', body: body, credentials: 'same-origin', keepalive: keepalive === true})
            .then(function (response) {
                return response.json();
            })
            .then(function (result) {
                if (result && result.token) {
                    token = result.token;
                }
            })
            .catch(function () {
                // The place is simply not kept this time.
            })
            .then(function () {
                inFlight = false;
            });
    }

    function schedule() {
        if (timer !== null) {
            return;
        }

        timer = window.setTimeout(function () {
            timer = null;
            send(false);
        }, SAVE_EVERY);
    }

    window.addEventListener('scroll', schedule, {passive: true});
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            send(true);
        }
    });
    window.addEventListener('pagehide', function () {
        send(true);
    });

    // The resume button, offered only when the saved place is beyond the first screen.
    var wrapper = document.querySelector('[data-lf-resume]');
    var button = wrapper ? wrapper.querySelector('button') : null;

    if (!button || saved <= 0) {
        return;
    }

    function target() {
        return textTop() + (saved / 1000) * text.offsetHeight;
    }

    if (target() < textTop() + window.innerHeight) {
        return;
    }

    wrapper.hidden = false;
    button.addEventListener('click', function () {
        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var top = target();

        window.scrollTo({top: top, behavior: reduced ? 'auto' : 'smooth'});

        // Focus goes to the first block at or after the place, so keyboard and screen reader users arrive there too.
        var blocks = text.children;

        for (var index = 0; index < blocks.length; index++) {
            if (blocks[index].getBoundingClientRect().top + window.pageYOffset >= top) {
                blocks[index].setAttribute('tabindex', '-1');
                blocks[index].focus({preventScroll: true});
                break;
            }
        }

        wrapper.hidden = true;
    });
}());
