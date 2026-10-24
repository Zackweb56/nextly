import { registerDataTableRenderer } from '../registry.js';
import escapeHtml from '../escape-html.js';
import { refreshDataTables, showFluxToast } from '../table-ui.js';

window.addEventListener('clinics-updated', refreshDataTables);

function renderClinic(value, type, row) {
    if (type !== 'display') {
        return value;
    }

    return `<a class="app-table-primary">
            ${escapeHtml(row.name)}
        </a>
        <div class="app-table-secondary">${escapeHtml(row.slug)}</div>`;
}

function renderSubscription(value, type, row) {
    if (type !== 'display') {
        return value;
    }

    return `<div>${escapeHtml(value)}</div>
        ${row.subscription_plan ? `<div class="app-table-secondary">${escapeHtml(row.subscription_plan)}</div>` : ''}`;
}

function renderClinicActions(value, type, row) {
    if (type !== 'display') {
        return value;
    }

    const statusIcon = row.is_active
        ? '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M7 4.5v11m6-11v11" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
        : '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="m7 4.5 8 5.5-8 5.5v-11Z" fill="currentColor" stroke="currentColor" stroke-linejoin="round"/></svg>';

    return `<div class="app-table-actions">
        <a class="app-table-icon-button" href="${escapeHtml(row.billing_url)}" data-clinic-details
            title="${escapeHtml(row.details_title)}" aria-label="${escapeHtml(row.details_title)}">
            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 3.5c-4.1 0-7 3.6-8 6.5 1 2.9 3.9 6.5 8 6.5s7-3.6 8-6.5c-1-2.9-3.9-6.5-8-6.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/></svg>
        </a>
        <button type="button" class="app-table-icon-button" data-clinic-members
            data-members-url="${escapeHtml(row.members_url)}"
            data-title="${escapeHtml(row.members_title)}"
            title="${escapeHtml(row.members_title)}" aria-label="${escapeHtml(row.members_title)}">
            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M7.5 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm5 1a2.5 2.5 0 1 0 0-5m-10 11v-1.5a5 5 0 0 1 10 0V16h-10Zm11-5a4 4 0 0 1 3.5 4v1h-2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <button type="button" class="app-table-icon-button" data-clinic-toggle
            data-status-url="${escapeHtml(row.status_url)}"
            data-confirm="${escapeHtml(row.action_confirm)}"
            title="${escapeHtml(row.action_label)}" aria-label="${escapeHtml(row.action_label)}">
            ${statusIcon}
        </button>
    </div>`;
}

registerDataTableRenderer('clinic', renderClinic);
registerDataTableRenderer('subscription', renderSubscription);
registerDataTableRenderer('clinic-actions', renderClinicActions);

function renderClinicMembers(content, users) {
    const labels = content.dataset;

    if (users.length === 0) {
        content.innerHTML = `<p class="py-8 text-center text-sm text-app-muted">${escapeHtml(labels.emptyLabel)}</p>`;

        return;
    }

    content.innerHTML = users.map((user) => {
        const roles = user.roles.length
            ? user.roles.map((role) => `<span class="app-badge app-badge-neutral">${escapeHtml(role)}</span>`).join('')
            : `<span class="text-sm text-app-muted">${escapeHtml(labels.noRolesLabel)}</span>`;
        const permissions = user.permissions.length
            ? user.permissions.map((permission) => `<span class="app-badge app-badge-info">${escapeHtml(permission)}</span>`).join('')
            : `<span class="text-sm text-app-muted">${escapeHtml(labels.noPermissionsLabel)}</span>`;

        return `<article class="app-member-card">
            <div class="min-w-0">
                <h3 class="truncate font-medium">${escapeHtml(user.name)}</h3>
                <p class="mt-0.5 break-all text-sm text-app-muted">${escapeHtml(user.email)}</p>
            </div>
            <section class="mt-4">
                <h4 class="app-member-section-title">${escapeHtml(labels.rolesLabel)}</h4>
                <div class="app-member-badges">${roles}</div>
            </section>
            <section class="mt-4">
                <h4 class="app-member-section-title">${escapeHtml(labels.permissionsLabel)}</h4>
                <div class="app-member-badges">${permissions}</div>
            </section>
        </article>`;
    }).join('');
}

async function showClinicMembers(button) {
    const dialog = document.querySelector('#clinic-members-dialog');
    const content = dialog?.querySelector('[data-members-content]');
    const subtitle = dialog?.querySelector('[data-members-subtitle]');
    const title = dialog?.querySelector('[data-members-title]');

    if (!dialog || !content || !subtitle || !title) {
        return;
    }

    title.textContent = button.dataset.title ?? '';
    subtitle.textContent = '';
    content.innerHTML = `<p class="text-sm text-app-muted">${escapeHtml(content.dataset.loadingLabel)}</p>`;
    dialog.showModal();

    try {
        const response = await fetch(button.dataset.membersUrl ?? '', {
            headers: { Accept: 'application/json' },
        });
        const result = await response.json();

        if (!response.ok) {
            throw new Error(result.message || content.dataset.errorLabel);
        }

        const countLabel = result.data.length === 1
            ? content.dataset.userLabel
            : content.dataset.usersLabel;
        subtitle.textContent = `${result.data.length} ${countLabel}`;
        renderClinicMembers(content, result.data);
    } catch (error) {
        const message = error instanceof Error ? error.message : content.dataset.errorLabel;
        content.innerHTML = `<p class="py-8 text-center text-sm text-danger">${escapeHtml(message)}</p>`;
    }
}

let pendingClinicStatusButton = null;

document.addEventListener('click', async (event) => {
    if (!(event.target instanceof Element)) {
        return;
    }

    const closeButton = event.target.closest('[data-dialog-close]');
    if (closeButton) {
        closeButton.closest('dialog')?.close();

        return;
    }

    const membersButton = event.target.closest('[data-clinic-members]');
    if (membersButton) {
        await showClinicMembers(membersButton);

        return;
    }

    const toggleButton = event.target.closest('[data-clinic-toggle]');
    if (toggleButton && !toggleButton.disabled) {
        pendingClinicStatusButton = toggleButton;
        const dialog = document.querySelector('#clinic-status-dialog');
        const message = dialog?.querySelector('[data-confirm-message]');
        const errorMessage = dialog?.querySelector('[data-confirm-error]');

        if (!dialog || !message || !errorMessage) {
            pendingClinicStatusButton = null;

            return;
        }

        message.textContent = toggleButton.dataset.confirm ?? '';
        errorMessage.classList.add('hidden');
        dialog.showModal();

        return;
    }

    if (!event.target.closest('[data-confirm-action]') || !pendingClinicStatusButton) {
        return;
    }

    const button = pendingClinicStatusButton;
    const dialog = document.querySelector('#clinic-status-dialog');
    const confirmButton = dialog?.querySelector('[data-confirm-action]');
    const errorMessage = dialog?.querySelector('[data-confirm-error]');

    if (!dialog || !confirmButton || !errorMessage) {
        return;
    }

    confirmButton.disabled = true;
    errorMessage.classList.add('hidden');

    try {
        const response = await fetch(button.dataset.statusUrl ?? '', {
            method: 'PATCH',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
        });
        const result = await response.json();

        if (!response.ok) {
            throw new Error(result.message || 'The clinic status could not be updated.');
        }

        dialog.close();
        showFluxToast(result.message);
        refreshDataTables();
    } catch (error) {
        errorMessage.textContent = error instanceof Error
            ? error.message
            : 'The clinic status could not be updated.';
        errorMessage.classList.remove('hidden');
    } finally {
        confirmButton.disabled = false;
    }
});

document.addEventListener('close', (event) => {
    if (event.target instanceof HTMLDialogElement && event.target.id === 'clinic-status-dialog') {
        pendingClinicStatusButton = null;
    }
}, true);
