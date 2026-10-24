import { getDataTableRenderer } from './registry.js';
import escapeHtml from './escape-html.js';
import { showTableFeedback } from './table-ui.js';

import.meta.glob('./tables/*.js', { eager: true });

const dataTablesVersion = '2.3.4';
const jQueryVersion = '3.7.1';
let dataTablesPromise;

function loadScript(src) {
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.onload = resolve;
        script.onerror = () => reject(new Error(`Could not load required script: ${src}`));
        document.head.append(script);
    });
}

function loadDataTables() {
    if (typeof window.DataTable === 'function') {
        return Promise.resolve();
    }

    if (dataTablesPromise) {
        return dataTablesPromise;
    }

    const stylesheet = document.createElement('link');
    stylesheet.rel = 'stylesheet';
    stylesheet.href = `https://cdn.datatables.net/${dataTablesVersion}/css/dataTables.dataTables.min.css`;
    document.head.append(stylesheet);

    dataTablesPromise = (async () => {
        if (!window.jQuery) {
            await loadScript(`https://code.jquery.com/jquery-${jQueryVersion}.min.js`);
        }

        if (typeof window.DataTable !== 'function') {
            await loadScript(`https://cdn.datatables.net/${dataTablesVersion}/js/dataTables.min.js`);
        }

        if (typeof window.DataTable !== 'function') {
            throw new Error('DataTables.net loaded without its constructor. Check that the CDN scripts are allowed.');
        }
    })().catch((error) => {
        dataTablesPromise = undefined;
        throw error;
    });

    return dataTablesPromise;
}

function createRenderer(name) {
    return (value, type, row) => {
        const registeredRenderer = getDataTableRenderer(name);

        if (registeredRenderer) {
            return registeredRenderer(value, type, row);
        }

        if (type !== 'display') {
            return typeof value === 'object' ? value?.label ?? '' : value ?? '';
        }

        if (name === 'badge') {
            const tone = ['success', 'warning', 'danger', 'info', 'neutral'].includes(value?.tone)
                ? value.tone
                : 'neutral';

            return `<span class="app-badge app-badge-${tone}">${escapeHtml(value?.label)}</span>`;
        }

        return escapeHtml(value);
    };
}

async function initializeTable(table) {
    if (table._dataTable) {
        return;
    }

    try {
        await loadDataTables();
        if (!table.isConnected || table._dataTable) {
            return;
        }

        const config = JSON.parse(table.dataset.config);
        const tableColumns = config.columns.map((column) => ({
            data: column.data,
            title: column.title,
            orderable: column.orderable !== false,
            searchable: column.searchable !== false,
            render: column.render ? createRenderer(column.render) : createRenderer(undefined),
        }));

        table._dataTable = new window.DataTable(table, {
            data: config.ajaxUrl ? undefined : config.rows,
            ajax: config.ajaxUrl ? {
                url: config.ajaxUrl,
                error: () => showTableFeedback(table.closest('[data-data-table-component]'), 'Unable to load table data. Please try again.', true),
            } : undefined,
            serverSide: Boolean(config.ajaxUrl),
            processing: Boolean(config.ajaxUrl),
            columns: tableColumns,
            pageLength: config.pageLength,
            lengthMenu: [10, 25, 50],
            order: config.order,
            searching: config.searchable,
            autoWidth: false,
            scrollX: true,
            layout: {
                topStart: 'pageLength',
                topEnd: config.searchable ? { search: { placeholder: 'Search…' } } : null,
                bottomStart: 'info',
                bottomEnd: 'paging',
            },
            language: {
                emptyTable: 'No records found',
                info: 'Showing _START_ to _END_ of _TOTAL_ entries',
                infoEmpty: 'Showing 0 to 0 of 0 entries',
                lengthMenu: 'Show _MENU_ entries',
                processing: 'Loading…',
                zeroRecords: 'No matching records found',
            },
        });

    } catch (error) {
        showTableFeedback(table.closest('[data-data-table-component]'), error.message, true);
    }
}

function initializeTables() {
    document.querySelectorAll('[data-data-table]').forEach(initializeTable);
    initializeDialogs();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeTables, { once: true });
} else {
    initializeTables();
}

document.addEventListener('livewire:navigated', initializeTables);
document.addEventListener('livewire:navigating', () => {
    document.querySelectorAll('[data-data-table]').forEach((table) => {
        if (table._dataTable) {
            table._dataTable.destroy();
            delete table._dataTable;
        }
    });
});

function initializeDialogs() {
    document.querySelectorAll('.app-table-dialog').forEach((dialog) => {
        if (dialog.dataset.handlersAttached) {
            return;
        }

        dialog.dataset.handlersAttached = 'true';
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    });
}