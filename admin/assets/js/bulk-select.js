// Select-all for a list with row checkboxes. The list works without this script: rows can be ticked and posted as
// they are. The script only shows the header checkbox (with a mixed state), keeps it in step with the rows, and
// shows how many rows are selected. A header checkbox carries data-lf-select-all="group" and its rows
// data-lf-select-item="group".
(function () {
    'use strict';

    Array.prototype.forEach.call(document.querySelectorAll('[data-lf-select-all]'), function (all) {
        var group = all.getAttribute('data-lf-select-all');
        var items = Array.prototype.slice.call(document.querySelectorAll('[data-lf-select-item="' + group + '"]'));
        // A counter with a group name belongs to that list alone, so two lists on one screen keep separate counts.
        var counters = Array.prototype.slice.call(document.querySelectorAll('[data-lf-selected-count]')).filter(function (counter) {
            var named = counter.getAttribute('data-lf-selected-count');

            return named === '' || named === group;
        });
        var fallback = document.querySelector('[data-lf-select-fallback]');

        if (items.length === 0) {
            return;
        }

        all.hidden = false;

        if (fallback) {
            fallback.hidden = true;
        }

        function refresh() {
            var ticked = items.filter(function (item) {
                return item.checked;
            }).length;

            all.checked = ticked === items.length;
            all.indeterminate = ticked > 0 && ticked < items.length;

            counters.forEach(function (counter) {
                counter.hidden = false;
                counter.textContent = ticked === 0 ? 'None selected' : ticked + ' selected';
            });
        }

        all.addEventListener('change', function () {
            items.forEach(function (item) {
                item.checked = all.checked;
            });
            refresh();
        });

        items.forEach(function (item) {
            item.addEventListener('change', refresh);
        });

        refresh();
    });
}());
