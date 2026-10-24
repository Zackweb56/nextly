import { registerDataTableRenderer } from '../registry.js';
import escapeHtml from '../escape-html.js';
import { refreshDataTables } from '../table-ui.js';

window.addEventListener('plans-updated', refreshDataTables);

function renderPlanCell(value, type, row) {
    if (type !== 'display') {
        return value;
    }

    return `<div class="app-table-primary">${escapeHtml(row.name)}</div>
        <div class="app-table-secondary">${escapeHtml(row.slug)}</div>`;
}

function renderPlanPrice(value, type) {
    if (type !== 'display') {
        return value;
    }

    return `${escapeHtml(value)} MAD`;
}

function renderPlanActions(value, type, row) {
    if (type !== 'display') {
        return value;
    }

    return `<div class="app-table-actions">
        <button type="button" class="app-table-icon-button" data-plan-edit
            data-plan-id="${escapeHtml(row.id)}" title="${escapeHtml(row.edit_label)}" aria-label="${escapeHtml(row.edit_label)}">
            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="m13.5 3.5 3 3M4 16l3.5-.7L16.5 6.3a2.1 2.1 0 0 0-3-3L4.5 12.3 4 16Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <button type="button" class="app-table-icon-button" data-plan-delete
            data-plan-id="${escapeHtml(row.id)}"
            data-plan-confirm="${escapeHtml(row.delete_confirm)}"
            title="${escapeHtml(row.delete_label)}" aria-label="${escapeHtml(row.delete_label)}">
            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M3.5 5.5h13m-11.5 0 .7 11h8.6l.7-11M8 5.5V3.8h4v1.7m-3 3v5m2-5v5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
    </div>`;
}

registerDataTableRenderer('plan', renderPlanCell);
registerDataTableRenderer('plan-price', renderPlanPrice);
registerDataTableRenderer('plan-actions', renderPlanActions);

let pendingPlanDeleteId = null;

document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) {
        return;
    }

    const editButton = event.target.closest('[data-plan-edit]');
    if (editButton) {
        window.Livewire.dispatch('plan-edit-requested', {
            planId: Number(editButton.dataset.planId),
        });
        return;
    }

    const deleteButton = event.target.closest('[data-plan-delete]');
    if (deleteButton) {
        pendingPlanDeleteId = Number(deleteButton.dataset.planId);

        const dialog = document.querySelector('#plan-delete-dialog');
        const message = dialog?.querySelector('[data-plan-delete-message]');

        if (!dialog || !message) {
            pendingPlanDeleteId = null;
            return;
        }

        message.textContent = deleteButton.dataset.planConfirm ?? '';
        dialog.showModal();
        return;
    }

    if (!event.target.closest('[data-plan-delete-confirm]') || pendingPlanDeleteId === null) {
        return;
    }

    window.Livewire.dispatch('plan-delete-confirmed', { planId: pendingPlanDeleteId });
    document.querySelector('#plan-delete-dialog')?.close();
    pendingPlanDeleteId = null;
});

document.addEventListener('close', (event) => {
    if (event.target instanceof HTMLDialogElement && event.target.id === 'plan-delete-dialog') {
        pendingPlanDeleteId = null;
    }
}, true);
