/* Lumora Fiction term lookup for an administrator's merge and create-term screens.
   A box marked data-lf-lookup="pick" (the term to keep on the merge screen) offers the matching existing terms as a
   list to choose from; a box marked data-lf-lookup="warn" (the name on the create screen) lists the terms that
   already exist, with links, so a duplicate is noticed before it is saved. The server still checks whatever is
   submitted, so a typed or stale value is handled exactly as without this script, and nothing loads from another
   site or needs an inline script or style. */

(function () {
    'use strict';

    var script = document.currentScript || document.querySelector('script[data-lf-lookup-url]');
    var url = script ? script.getAttribute('data-lf-lookup-url') : '';
    var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-lf-lookup]'));

    if (!url || boxes.length === 0 || !window.fetch) {
        return;
    }

    var DELAY = 200;
    var MIN = 2;

    function describe(match) {
        var text = match.alias !== '' ? match.alias + ', same as ' + match.name : match.name;
        var facts = [];

        if (match.fandom !== '') {
            facts.push(match.fandom);
        }

        facts.push(match.stories + (match.stories === 1 ? ' story' : ' stories'));

        if (!match.reviewed) {
            facts.push('not yet approved');
        }

        return text + ' (' + facts.join(', ') + ')';
    }

    function Lookup(input) {
        this.input = input;
        this.mode = input.getAttribute('data-lf-lookup');
        this.matches = [];
        this.active = -1;
        this.timer = 0;
        this.request = 0;
        this.controller = null;

        var id = input.id || 'lf-lookup';

        this.list = document.createElement('ul');
        this.list.id = id + '_matches';
        this.list.hidden = true;

        this.status = document.createElement('p');
        this.status.className = 'lf-visually-hidden';
        this.status.setAttribute('role', 'status');

        if (this.mode === 'pick') {
            this.list.className = 'lf-suggest';
            this.list.setAttribute('role', 'listbox');
            this.list.setAttribute('aria-label', 'Matching terms');
            input.setAttribute('role', 'combobox');
            input.setAttribute('aria-autocomplete', 'list');
            input.setAttribute('aria-expanded', 'false');
            input.setAttribute('aria-controls', this.list.id);
            input.parentNode.insertBefore(this.list, input.nextSibling);
        } else {
            /* A warning is not an input widget, so it is a plain list of links after the field's hint. */
            this.box = document.createElement('div');
            this.box.className = 'lf-notice lf-notice--warning lf-lookup-warning';
            this.box.hidden = true;

            var heading = document.createElement('p');

            heading.className = 'lf-lookup-warning__title';
            heading.textContent = 'Already exists:';
            this.list.className = 'lf-lookup-warning__list';
            this.box.appendChild(heading);
            this.box.appendChild(this.list);
            input.parentNode.appendChild(this.box);
        }

        input.parentNode.appendChild(this.status);
    }

    Lookup.prototype.query = function (text) {
        var query = new URLSearchParams();
        var merging = this.input.getAttribute('data-lf-lookup-merging');
        var type = this.input.getAttribute('data-lf-lookup-type');
        var fandomId = this.input.getAttribute('data-lf-lookup-fandom');
        var fandom = fandomId ? document.getElementById(fandomId) : null;

        query.set('q', text);

        if (merging) {
            query.set('merging', merging);
        } else {
            query.set('type', type || '');

            if (fandom) {
                query.set('fandom', fandom.value);
            }
        }

        return query.toString();
    };

    Lookup.prototype.close = function () {
        this.list.hidden = true;
        this.list.textContent = '';
        this.matches = [];
        this.active = -1;

        if (this.box) {
            this.box.hidden = true;
        }

        if (this.mode === 'pick') {
            this.input.setAttribute('aria-expanded', 'false');
            this.input.removeAttribute('aria-activedescendant');
        }
    };

    Lookup.prototype.show = function (matches) {
        var self = this;

        this.close();

        if (matches.length === 0) {
            this.status.textContent = '';

            return;
        }

        this.matches = matches;

        matches.forEach(function (match, index) {
            var item = document.createElement('li');

            if (self.mode === 'pick') {
                item.id = self.list.id + '-' + index;
                item.className = 'lf-suggest__option';
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', 'false');
                item.textContent = describe(match);

                /* Pressing must not take focus from the box, or the list would close before the click lands. */
                item.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                });
                item.addEventListener('click', function () {
                    self.accept(index);
                });
            } else {
                var link = document.createElement('a');

                link.href = match.url;
                link.textContent = describe(match);
                item.appendChild(link);
            }

            self.list.appendChild(item);
        });

        this.list.hidden = false;

        if (this.box) {
            this.box.hidden = false;
        }

        if (this.mode === 'pick') {
            this.input.setAttribute('aria-expanded', 'true');
        }

        this.status.textContent = matches.length + (matches.length === 1 ? ' existing term matches' : ' existing terms match')
            + (this.mode === 'pick' ? '. Use the up and down arrow keys to choose one.' : '.');
    };

    Lookup.prototype.move = function (step) {
        var count = this.matches.length;

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

    /* The exact canonical name goes in the box; the server resolves it on submit as it always did. */
    Lookup.prototype.accept = function (index) {
        var match = this.matches[index];

        if (!match) {
            return;
        }

        this.input.value = match.name;
        this.close();
        this.status.textContent = match.name + ' chosen.';
        this.input.focus();
    };

    Lookup.prototype.ask = function () {
        var self = this;
        var text = this.input.value.trim();
        var number = ++this.request;

        if (this.controller) {
            this.controller.abort();
        }

        if (text.length < MIN) {
            this.close();
            this.status.textContent = '';

            return;
        }

        this.controller = window.AbortController ? new AbortController() : null;

        window.fetch(url + '?' + this.query(text), {
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

            self.show(data.ok && Array.isArray(data.matches) ? data.matches : []);
        }).catch(function () {
            if (number === self.request) {
                self.close();
            }
        });
    };

    Lookup.prototype.start = function () {
        var self = this;
        var input = this.input;
        var fandomId = input.getAttribute('data-lf-lookup-fandom');
        var fandom = fandomId ? document.getElementById(fandomId) : null;

        function later() {
            window.clearTimeout(self.timer);
            self.timer = window.setTimeout(function () {
                self.ask();
            }, DELAY);
        }

        input.addEventListener('input', later);

        /* A warning depends on the fandom too: the same name in two fandoms is two terms. */
        if (fandom && this.mode === 'warn') {
            fandom.addEventListener('input', later);
        }

        if (this.mode !== 'pick') {
            return;
        }

        input.addEventListener('keydown', function (event) {
            var open = !self.list.hidden;

            if (event.key === 'ArrowDown' && open) {
                event.preventDefault();
                self.move(1);
            } else if (event.key === 'ArrowUp' && open) {
                event.preventDefault();
                self.move(-1);
            } else if (event.key === 'Enter' && open && self.active >= 0) {
                /* Only a term the administrator has moved to is taken; Enter on its own still sends the form. */
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

    boxes.forEach(function (input) {
        new Lookup(input).start();
    });
}());
