/* File: assets/js/local-retention-admin.js */
(function () {
    'use strict';

    const filter = document.getElementById('awvp-retention-filter');
    const table = document.getElementById('awvp-retention-table');
    const selectVisible = document.getElementById('awvp-retention-select-visible');
    const count = document.getElementById('awvp-retention-filter-count');
    if (!filter || !table || !selectVisible || !count) {
        return;
    }

    const rows = Array.from(table.querySelectorAll('tbody tr[data-search]'));
    const labels = window.awvpRetentionAdmin || {};

    function visibleRows() {
        return rows.filter(function (row) {
            return row.style.display !== 'none';
        });
    }

    function shownLabel(visible, total) {
        const template = typeof labels.shown === 'string' && labels.shown !== ''
            ? labels.shown
            : '%1$d / %2$d videos shown';
        return template.replace('%1$d', String(visible)).replace('%2$d', String(total));
    }

    function refreshCount() {
        const visible = visibleRows();
        count.textContent = shownLabel(visible.length, rows.length);
        const selectable = visible.map(function (row) {
            return row.querySelector('input[name="video_ids[]"]');
        }).filter(function (box) {
            return box && !box.disabled;
        });
        selectVisible.checked = selectable.length > 0 && selectable.every(function (box) {
            return box.checked;
        });
        selectVisible.indeterminate = selectable.some(function (box) {
            return box.checked;
        }) && !selectVisible.checked;
    }

    filter.addEventListener('input', function () {
        const query = filter.value.trim().toLowerCase();
        rows.forEach(function (row) {
            row.style.display = !query || (row.dataset.search || '').indexOf(query) !== -1 ? '' : 'none';
        });
        refreshCount();
    });

    selectVisible.addEventListener('change', function () {
        visibleRows().forEach(function (row) {
            const box = row.querySelector('input[name="video_ids[]"]');
            if (box && !box.disabled) {
                box.checked = selectVisible.checked;
            }
        });
        refreshCount();
    });

    table.addEventListener('change', function (event) {
        if (event.target && event.target.matches('input[name="video_ids[]"]')) {
            refreshCount();
        }
    });

    refreshCount();
}());
