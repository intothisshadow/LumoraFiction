/* Lumora Fiction suggestions for the story form.
   While a writer types a fandom, character, relationship or tag, this shows the existing entries that match,
   so a name is picked instead of being spelled a new way. Each field holds a comma-separated list, so the
   suggestions are for the name being typed and a chosen one replaces only that name. Nothing loads from
   another site and nothing needs an inline script or style. If this file cannot run, the fields work as
   plain text boxes and the server matches names and aliases as it always has. */

(function () {
    'use strict';

    var script = document.currentScript || document.querySelector('script[data-lf-suggest-url]');
    var url = script ? script.getAttribute('data-lf-suggest-url') : '';
    var inputs = Array.prototype.slice.call(document.querySelectorAll('[data-lf-suggest]'));

    if (!url || inputs.length === 0 || !window.fetch) {
        return;
    }

    var DELAY = 200;
    var MIN = 2;

    function Suggest(input) {
        this.input = input;
        this.kind = input.getAttribute('data-lf-suggest');
        this.items = [];
        this.active = -1;
        this.timer = 0;
        this.request = 0;
        this.controller = null;

        var id = input.id || 'lf-suggest-' + this.kind;

        this.list = document.createElement('ul');
        this.list.id = id + '_suggest';
        this.list.className = 'lf-suggest';
        this.list.setAttribute('role', 'listbox');
        this.list.setAttribute('aria-label', 'Matching entries');
        this.list.hidden = true;

        this.status = document.createElement('p');
        this.status.className = 'lf-visually-hidden';
        this.status.setAttribute('role', 'status');

        /* The list sits straight under the box, ahead of the hint, and takes its own room rather than covering anything. */
        input.parentNode.insertBefore(this.list, input.nextSibling);
        input.parentNode.insertBefore(this.status, this.list.nextSibling);

        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', this.list.id);
    }

    /* The name being typed: from just after the comma or line break before the caret to the next one. */
    Suggest.prototype.currentSegment = function () {
        var value = this.input.value;
        var caret = this.input.selectionStart === null ? value.length : this.input.selectionStart;
        var start = Math.max(value.lastIndexOf(',', caret - 1), value.lastIndexOf('\n', caret - 1)) + 1;
        var rest = value.slice(caret).search(/[,\n]/);
        var end = rest === -1 ? value.length : caret + rest;
        var lead = value.slice(start, end).match(/^\s*/)[0].length;

        return { start: start + lead, end: end, text: value.slice(start + lead, end).trim() };
    };

    Suggest.prototype.names = function (input, exceptSegment) {
        var value = input.value;

        if (exceptSegment) {
            value = value.slice(0, exceptSegment.start) + value.slice(exceptSegment.end);
        }

        return value.split(/[,\n]/).map(function (name) {
            return name.trim();
        }).filter(function (name) {
            return name !== '';
        });
    };

    Suggest.prototype.close = function () {
        this.list.hidden = true;
        this.list.textContent = '';
        this.items = [];
        this.active = -1;
        this.input.setAttribute('aria-expanded', 'false');
        this.input.removeAttribute('aria-activedescendant');
    };

    Suggest.prototype.label = function (item) {
        var text = item.alias !== '' ? item.alias + ', same as ' + item.name : item.name;

        return item.reviewed ? text : text + ' (new, awaiting review)';
    };

    Suggest.prototype.show = function (items) {
        var self = this;

        this.list.textContent = '';
        this.items = items;
        this.active = -1;
        this.input.removeAttribute('aria-activedescendant');

        if (items.length === 0) {
            this.close();
            this.status.textContent = '';

            return;
        }

        items.forEach(function (item, index) {
            var option = document.createElement('li');

            option.id = self.list.id + '-' + index;
            option.className = 'lf-suggest__option';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.textContent = self.label(item);

            /* Pressing must not take focus from the box, or the list would close before the click lands. */
            option.addEventListener('mousedown', function (event) {
                event.preventDefault();
            });
            option.addEventListener('click', function () {
                self.accept(index);
            });

            self.list.appendChild(option);
        });

        this.list.hidden = false;
        this.input.setAttribute('aria-expanded', 'true');
        this.status.textContent = items.length + (items.length === 1 ? ' match' : ' matches') + ' available. Use the up and down arrow keys to choose one.';
    };

    Suggest.prototype.move = function (step) {
        var count = this.items.length;

        if (count === 0) {
            return;
        }

        var options = this.list.children;

        if (this.active >= 0) {
            options[this.active].setAttribute('aria-selected', 'false');
        }

        this.active = this.active === -1 ? (step > 0 ? 0 : count - 1) : (this.active + step + count) % count;
        options[this.active].setAttribute('aria-selected', 'true');
        this.input.setAttribute('aria-activedescendant', options[this.active].id);
    };

    /* Puts the chosen name in place of the name being typed, with the comma and space after it handled. */
    Suggest.prototype.accept = function (index) {
        var item = this.items[index];
        var segment = this.currentSegment();

        if (!item) {
            return;
        }

        var value = this.input.value;
        var after = value.slice(segment.end);
        var inserted = item.name + (after === '' ? ', ' : '');

        this.input.value = value.slice(0, segment.start) + inserted + after;

        var caret = segment.start + item.name.length + (after === '' ? 2 : 0);

        this.input.setSelectionRange(caret, caret);
        this.close();
        this.status.textContent = item.name + ' added.';
        this.input.dispatchEvent(new Event('input', { bubbles: true }));
    };

    Suggest.prototype.ask = function () {
        var self = this;
        var segment = this.currentSegment();
        var number = ++this.request;

        if (this.controller) {
            this.controller.abort();
        }

        if (segment.text.length < MIN) {
            this.close();

            return;
        }

        var query = new URLSearchParams();
        var fandom = document.getElementById('fandom');

        query.set('type', this.kind);
        query.set('q', segment.text);
        query.set('skip', this.names(this.input, segment).join(','));

        /* A character or relationship is narrowed by the fandoms already named on the form. */
        if (fandom && (this.kind === 'character' || this.kind === 'relationship')) {
            query.set('fandoms', this.names(fandom).join(','));
        }

        this.controller = window.AbortController ? new AbortController() : null;

        window.fetch(url + '?' + query.toString(), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
            signal: this.controller ? this.controller.signal : undefined
        }).then(function (response) {
            return response.ok ? response.json() : { ok: false };
        }).then(function (data) {
            /* An answer to an earlier keystroke arriving late is ignored. */
            if (number !== self.request) {
                return;
            }

            self.show(data.ok && Array.isArray(data.suggestions) ? data.suggestions : []);
        }).catch(function () {
            if (number === self.request) {
                self.close();
            }
        });
    };

    Suggest.prototype.start = function () {
        var self = this;
        var input = this.input;

        input.addEventListener('input', function () {
            window.clearTimeout(self.timer);
            self.timer = window.setTimeout(function () {
                self.ask();
            }, DELAY);
        });

        input.addEventListener('keydown', function (event) {
            var open = !self.list.hidden;

            if (event.key === 'ArrowDown' && open) {
                event.preventDefault();
                self.move(1);
            } else if (event.key === 'ArrowUp' && open) {
                event.preventDefault();
                self.move(-1);
            } else if ((event.key === 'Enter' || event.key === 'Tab') && open && self.active >= 0) {
                /* Only a name the writer has moved to is taken; Enter on its own still sends the form. */
                event.preventDefault();
                self.accept(self.active);
            } else if (event.key === 'Escape' && open) {
                event.preventDefault();
                self.close();
            }
        });

        input.addEventListener('blur', function () {
            window.clearTimeout(self.timer);
            self.request++;
            self.close();
        });
    };

    inputs.forEach(function (input) {
        new Suggest(input).start();
    });
}());
