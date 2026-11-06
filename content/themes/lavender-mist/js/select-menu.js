/*
 * Replaces the browser's own dropdown list on the theme's select boxes with a styled one, because browsers
 * without customisable selects (Firefox and its forks, Safari) draw that list themselves and ignore CSS.
 * The real select stays in the page, hidden, and still holds the value and fires "change", so the reader
 * settings and any form keep working. Without this script the plain select is used.
 */
(function () {
    'use strict';

    // Browsers that can style the native list do it in the stylesheet, so they keep the native control.
    if (window.CSS && CSS.supports && CSS.supports('appearance', 'base-select')) {
        return;
    }

    var counter = 0;

    function enhance(select) {
        counter += 1;

        var uid = 'lf-menu-' + counter;
        var label = select.id ? document.querySelector('label[for="' + select.id + '"]') : null;
        var wrap = document.createElement('div');
        var button = document.createElement('button');
        var list = document.createElement('ul');
        var options = [];
        var active = 0;
        var typed = '';
        var typedTimer = null;

        if (label && !label.id) {
            label.id = uid + '-label';
        }

        wrap.className = 'lf-menu';
        button.type = 'button';
        button.id = uid + '-button';
        button.className = 'lf-menu__button';
        button.setAttribute('aria-haspopup', 'listbox');
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-controls', uid + '-list');

        if (label) {
            button.setAttribute('aria-labelledby', label.id + ' ' + button.id);
        }

        // Form fields keep their hint, error and required state, and fill the width of the form.
        if (select.classList.contains('lf-form__input')) {
            wrap.className += ' lf-menu--field';

            ['aria-describedby', 'aria-invalid'].forEach(function (name) {
                if (select.hasAttribute(name)) {
                    button.setAttribute(name, select.getAttribute(name));
                }
            });

            if (select.required) {
                button.setAttribute('aria-required', 'true');
            }
        }

        button.disabled = select.disabled;

        list.id = uid + '-list';
        list.className = 'lf-menu__list';
        list.setAttribute('role', 'listbox');
        list.tabIndex = -1;
        list.hidden = true;

        if (label) {
            list.setAttribute('aria-labelledby', label.id);
        }

        Array.prototype.forEach.call(select.options, function (source, index) {
            var item = document.createElement('li');

            item.id = uid + '-option-' + index;
            item.className = 'lf-menu__option';
            item.setAttribute('role', 'option');
            item.textContent = source.textContent;
            item.addEventListener('click', function () {
                choose(index);
                button.focus();
            });
            item.addEventListener('mousemove', function () {
                highlight(index);
            });
            list.appendChild(item);
            options.push(item);
        });

        select.parentNode.insertBefore(wrap, select);
        select.className += ' lf-menu__native';
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');
        wrap.appendChild(select);
        wrap.appendChild(button);
        wrap.appendChild(list);

        function sync() {
            var selected = select.selectedIndex;

            button.textContent = selected >= 0 ? options[selected].textContent : '';
            options.forEach(function (item, index) {
                item.setAttribute('aria-selected', index === selected ? 'true' : 'false');
            });
        }

        function highlight(index) {
            active = Math.max(0, Math.min(options.length - 1, index));
            options.forEach(function (item, position) {
                item.classList.toggle('lf-menu__option--active', position === active);
            });
            list.setAttribute('aria-activedescendant', options[active].id);
            options[active].scrollIntoView({block: 'nearest'});
        }

        function isOpen() {
            return !list.hidden;
        }

        function open() {
            list.hidden = false;
            button.setAttribute('aria-expanded', 'true');
            highlight(select.selectedIndex >= 0 ? select.selectedIndex : 0);
            list.focus();
        }

        function close() {
            list.hidden = true;
            button.setAttribute('aria-expanded', 'false');
        }

        function choose(index) {
            if (select.selectedIndex !== index) {
                select.selectedIndex = index;
                select.dispatchEvent(new Event('change', {bubbles: true}));
            }

            sync();
            close();
        }

        function typeAhead(character) {
            typed += character.toLowerCase();
            window.clearTimeout(typedTimer);
            typedTimer = window.setTimeout(function () {
                typed = '';
            }, 600);

            for (var offset = 0; offset < options.length; offset += 1) {
                var index = (active + offset + (typed.length === 1 ? 1 : 0)) % options.length;

                if (options[index].textContent.toLowerCase().indexOf(typed) === 0) {
                    highlight(index);
                    return;
                }
            }
        }

        button.addEventListener('click', function () {
            if (isOpen()) {
                close();
            } else {
                open();
            }
        });

        button.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                open();
            }
        });

        list.addEventListener('keydown', function (event) {
            switch (event.key) {
                case 'ArrowDown':
                    event.preventDefault();
                    highlight(active + 1);
                    break;
                case 'ArrowUp':
                    event.preventDefault();
                    highlight(active - 1);
                    break;
                case 'Home':
                    event.preventDefault();
                    highlight(0);
                    break;
                case 'End':
                    event.preventDefault();
                    highlight(options.length - 1);
                    break;
                case 'Enter':
                case ' ':
                    event.preventDefault();
                    choose(active);
                    button.focus();
                    break;
                case 'Escape':
                    event.preventDefault();
                    close();
                    button.focus();
                    break;
                case 'Tab':
                    close();
                    break;
                default:
                    if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
                        typeAhead(event.key);
                    }
            }
        });

        // Clicking away closes the list without stealing focus from whatever was clicked.
        document.addEventListener('click', function (event) {
            if (isOpen() && !wrap.contains(event.target)) {
                close();
            }
        });

        // The label would otherwise focus the hidden select.
        if (label) {
            label.addEventListener('click', function (event) {
                event.preventDefault();
                button.focus();
            });
        }

        select.addEventListener('change', sync);

        // A form reset puts the select back without a "change", so read it again once the reset is done.
        if (select.form) {
            select.form.addEventListener('reset', function () {
                window.setTimeout(sync, 0);
            });
        }

        sync();
    }

    // Registered after the reader script's own listener, so the saved choice is already in the select.
    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('select.lf-select, select.lf-form__input'), enhance);
    });
})();
