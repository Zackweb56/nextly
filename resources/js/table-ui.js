export function showTableFeedback(component, message, isError = false) {
    const feedback = component?.querySelector('[data-table-feedback]');

    if (!feedback) {
        return;
    }

    feedback.textContent = message;
    feedback.classList.remove('hidden', 'text-danger', 'text-success');
    feedback.classList.add(isError ? 'text-danger' : 'text-success');
}

export function showFluxToast(text, variant = 'success') {
    document.dispatchEvent(new CustomEvent('toast-show', {
        detail: {
            duration: 5000,
            slots: { text },
            dataset: { variant },
        },
    }));
}

export function refreshDataTables() {
    document.querySelectorAll('[data-data-table]').forEach((table) => {
        if (table._dataTable?.ajax) {
            table._dataTable.ajax.reload(null, false);
        }
    });
}
