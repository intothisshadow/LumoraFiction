// Cache header check: asks for a public page as a signed-out visitor, then again with the validator the first answer
// gave, and lists the headers of both so an Administrator can see what visitors and caches receive.
(function () {
    'use strict';

    var root = document.querySelector('[data-lf-cache-test]');

    if (!root || !window.fetch) {
        return;
    }

    var input = root.querySelector('#cache-test-path');
    var button = root.querySelector('[data-lf-cache-test-run]');
    var out = root.querySelector('[data-lf-cache-test-result]');
    var shown = ['cache-control', 'etag', 'vary', 'x-litespeed-tag', 'x-litespeed-cache-control', 'x-litespeed-cache', 'set-cookie', 'content-type'];

    function row(label, value) {
        var tr = document.createElement('tr');
        var th = document.createElement('th');
        var td = document.createElement('td');

        th.scope = 'row';
        th.textContent = label;
        td.textContent = value;
        tr.appendChild(th);
        tr.appendChild(td);

        return tr;
    }

    function table(title, response) {
        var wrap = document.createElement('div');
        var heading = document.createElement('h3');
        var tbl = document.createElement('table');
        var body = document.createElement('tbody');

        heading.textContent = title;
        tbl.className = 'lf-table';
        body.appendChild(row('Status', response.status + ' ' + (response.statusText || '')));

        shown.forEach(function (name) {
            var value = response.headers.get(name);

            if (value !== null) {
                body.appendChild(row(name, value));
            }
        });

        tbl.appendChild(body);
        wrap.appendChild(heading);
        wrap.appendChild(tbl);

        return wrap;
    }

    function verdict(first, second) {
        var control = (first.headers.get('cache-control') || '').toLowerCase();
        var lines = [];

        if (first.status !== 200) {
            lines.push('The page did not answer 200, so it is not cached.');
        } else if (first.headers.get('set-cookie')) {
            lines.push('The page sets a cookie, so it is not cached.');
        } else if (control.indexOf('no-store') !== -1) {
            lines.push('Not cached: the page is sent as not to be kept (' + control + ').');
        } else if (control.indexOf('public') !== -1) {
            lines.push('Cacheable by a shared cache and by browsers (' + control + ').');
        } else if (control.indexOf('private') !== -1) {
            lines.push('Kept by the visitor\'s own browser only (' + control + '). A shared cache keeps nothing.');
        } else {
            lines.push('No cache instructions were sent.');
        }

        if (second) {
            lines.push(second.status === 304 ? 'A browser that already holds the page gets a short 304 reply.' : 'A browser that already holds the page was sent the whole page again (status ' + second.status + ').');
        }

        var p = document.createElement('p');
        p.setAttribute('role', 'status');
        p.textContent = lines.join(' ');

        return p;
    }

    button.addEventListener('click', function () {
        var path = (input.value || '/').trim();

        if (path.charAt(0) !== '/' || path.indexOf('//') === 0) {
            out.textContent = 'Enter a path on this site that starts with a single /.';

            return;
        }

        out.textContent = 'Checking…';

        // No credentials and no browser cache, so the request is what a new visitor would send.
        var options = { credentials: 'omit', cache: 'no-store', redirect: 'manual' };

        fetch(path, options).then(function (first) {
            var etag = first.headers.get('etag');

            if (!etag) {
                return { first: first, second: null };
            }

            return fetch(path, { credentials: 'omit', cache: 'no-store', redirect: 'manual', headers: { 'If-None-Match': etag } }).then(function (second) {
                return { first: first, second: second };
            });
        }).then(function (answers) {
            out.textContent = '';
            out.appendChild(verdict(answers.first, answers.second));
            out.appendChild(table('First request', answers.first));

            if (answers.second) {
                out.appendChild(table('Second request, holding the page', answers.second));
            }
        }).catch(function () {
            out.textContent = 'The page could not be requested from here.';
        });
    });
}());
