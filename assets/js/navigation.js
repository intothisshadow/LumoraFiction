/*
 * Opens and closes the lists of pages under a main-menu entry. Without this script the lists stay open in the page and
 * the buttons stay hidden, so every link can still be reached. With it, the list opens from its button (click, tap,
 * Enter, Space or the Down arrow), moves with the arrow keys, closes with Escape (focus returns to the button) or a click
 * elsewhere, and opens on hover for a pointer that can hover, without ever being hidden from the keyboard.
 */
(function () {
    'use strict';

    var groups = document.querySelectorAll('.lf-nav__item--has-children');

    if (!groups.length) {
        return;
    }

    var canHover = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;

    function parts(group) {
        return {
            button: group.querySelector('[data-lf-nav-toggle]'),
            list: group.querySelector('.lf-nav__children')
        };
    }

    function links(group) {
        return Array.prototype.slice.call(group.querySelectorAll('.lf-nav__child-link'));
    }

    function isOpen(group) {
        var button = parts(group).button;

        return !!button && button.getAttribute('aria-expanded') === 'true';
    }

    function setOpen(group, open) {
        var button = parts(group).button;

        if (!button) {
            return;
        }

        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        group.classList.toggle('lf-nav__item--open', open);
    }

    function closeAll(except) {
        Array.prototype.forEach.call(groups, function (group) {
            if (group !== except && isOpen(group)) {
                setOpen(group, false);
            }
        });
    }

    Array.prototype.forEach.call(groups, function (group) {
        var button = parts(group).button;
        var nav = group.closest('.lf-nav');

        if (!button) {
            return;
        }

        if (nav) {
            nav.classList.add('lf-nav--enhanced');
        }

        button.hidden = false;

        button.addEventListener('click', function () {
            var open = !isOpen(group);

            closeAll(group);
            setOpen(group, open);
        });

        button.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                closeAll(group);
                setOpen(group, true);

                var first = links(group)[0];

                if (first) {
                    first.focus();
                }
            }
        });

        group.addEventListener('keydown', function (event) {
            var items = links(group);
            var index = items.indexOf(document.activeElement);

            if (event.key === 'Escape' && isOpen(group)) {
                event.preventDefault();
                setOpen(group, false);
                button.focus();

                return;
            }

            if (index === -1) {
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                items[(index + 1) % items.length].focus();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                items[(index - 1 + items.length) % items.length].focus();
            } else if (event.key === 'Home') {
                event.preventDefault();
                items[0].focus();
            } else if (event.key === 'End') {
                event.preventDefault();
                items[items.length - 1].focus();
            }
        });

        // Hover only adds the open state; a keyboard user who opened the list keeps it open while the pointer leaves.
        if (canHover) {
            group.addEventListener('mouseenter', function () {
                closeAll(group);
                setOpen(group, true);
            });

            group.addEventListener('mouseleave', function () {
                if (!group.contains(document.activeElement)) {
                    setOpen(group, false);
                }
            });
        }

        // Tabbing out of the list closes it, so it never stays open behind the next control.
        group.addEventListener('focusout', function (event) {
            if (!event.relatedTarget || !group.contains(event.relatedTarget)) {
                setOpen(group, false);
            }
        });
    });

    document.addEventListener('click', function (event) {
        Array.prototype.forEach.call(groups, function (group) {
            if (isOpen(group) && !group.contains(event.target)) {
                setOpen(group, false);
            }
        });
    });
}());
