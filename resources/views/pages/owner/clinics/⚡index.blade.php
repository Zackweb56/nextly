<?php

use App\Actions\CreateClinicAction;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Clinics')] class extends Component {
    public string $clinicName = '';
    public string $clinicPhone = '';
    public string $clinicCity = '';
    public bool $createAdmin = true;
    public string $adminName = '';
    public string $adminEmail = '';
    public string $adminPassword = '';

    public function generateAdminPassword(): void
    {
        $this->adminPassword = Str::password(24, symbols: true);
    }

    /**
     * Create a clinic and optionally its initial administrator.
     */
    public function createClinic(CreateClinicAction $createClinic): void
    {
        $validated = $this->validate([
            'clinicName' => ['required', 'string', 'max:255'],
            'clinicPhone' => ['nullable', 'string', 'max:20'],
            'clinicCity' => ['nullable', 'string', 'max:100'],
            'createAdmin' => ['required', 'boolean'],
            'adminName' => ['exclude_unless:createAdmin,true', 'required', 'string', 'max:255'],
            'adminEmail' => ['exclude_unless:createAdmin,true', 'required', 'string', 'email', 'max:255', 'unique:users,email'],
            'adminPassword' => [
                'exclude_unless:createAdmin,true',
                'required',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
            ],
        ]);

        $data = [
            'name' => $validated['clinicName'],
            'phone' => $validated['clinicPhone'] !== '' ? $validated['clinicPhone'] : null,
            'city' => $validated['clinicCity'] !== '' ? $validated['clinicCity'] : null,
        ];

        if ($validated['createAdmin']) {
            $data['admin_name'] = $validated['adminName'];
            $data['admin_email'] = $validated['adminEmail'];
            $data['admin_password'] = $validated['adminPassword'];
        }

        $createClinic->execute($data);

        $this->reset([
            'clinicName',
            'clinicPhone',
            'clinicCity',
            'adminName',
            'adminEmail',
            'adminPassword',
        ]);
        $this->createAdmin = true;

        Flux::modal('create-clinic')->close();
        Flux::toast(variant: 'success', text: __('Clinic created successfully.'));
        $this->dispatch('clinics-updated');
    }
}; ?>

<section class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Clinics') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Manage clinics, staff, and subscriptions across the platform.') }}</flux:text>
        </div>

        <flux:modal.trigger name="create-clinic">
            <flux:button variant="primary" icon="plus">{{ __('Create Clinic') }}</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:card class="overflow-hidden p-0">
        <x-data-table
            id="owner-clinics-table"
            :ajax-url="route('owner.clinics.data')"
            :columns="[
                ['data' => 'name', 'title' => __('Clinic'), 'render' => 'clinic', 'orderable' => false],
                ['data' => 'city', 'title' => __('City')],
                ['data' => 'phone', 'title' => __('Phone')],
                ['data' => 'subscription_status', 'title' => __('Subscription'), 'render' => 'subscription'],
                ['data' => 'status', 'title' => __('Clinic Status'), 'render' => 'badge', 'orderable' => false, 'searchable' => false],
                ['data' => 'created_at', 'title' => __('Created')],
                ['data' => 'id', 'title' => __('Actions'), 'render' => 'clinic-actions', 'orderable' => false, 'searchable' => false],
            ]"
            :order="[5, 'desc']"
        />
    </flux:card>

    <dialog id="clinic-members-dialog" class="app-table-dialog max-h-[90dvh] w-[min(42rem,calc(100%-2rem))] rounded-2xl border border-app-border bg-app-surface p-0 text-app-text shadow-2xl">
        <div class="flex max-h-[90dvh] flex-col">
            <header class="flex items-start justify-between gap-4 border-b border-app-border p-4 sm:p-6">
                <div class="min-w-0">
                    <h2 data-members-title class="text-lg font-semibold sm:text-xl">{{ __('Clinic users and permissions') }}</h2>
                    <p data-members-subtitle class="mt-1 text-sm text-app-muted"></p>
                </div>
                <button type="button" data-dialog-close class="app-dialog-close" title="{{ __('Close') }}" aria-label="{{ __('Close') }}">
                    <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                </button>
            </header>
            <div
                data-members-content
                data-loading-label="{{ __('Loading users…') }}"
                data-empty-label="{{ __('No users are assigned to this clinic.') }}"
                data-error-label="{{ __('Unable to load clinic users. Please try again.') }}"
                data-user-label="{{ __('user') }}"
                data-users-label="{{ __('users') }}"
                data-roles-label="{{ __('Roles') }}"
                data-permissions-label="{{ __('Permissions') }}"
                data-no-roles-label="{{ __('No roles assigned') }}"
                data-no-permissions-label="{{ __('No permissions assigned') }}"
                class="min-h-0 flex-1 space-y-3 overflow-y-auto p-4 sm:p-6"
            >
                <p data-members-loading class="text-sm text-app-muted">{{ __('Loading users…') }}</p>
            </div>
        </div>
    </dialog>

    <dialog id="clinic-status-dialog" class="app-table-dialog w-[min(28rem,calc(100%-2rem))] rounded-2xl border border-app-border bg-app-surface p-5 text-app-text shadow-2xl sm:p-6">
        <h2 data-confirm-title class="text-lg font-semibold">{{ __('Confirm clinic status change') }}</h2>
        <p data-confirm-message class="mt-2 text-sm text-app-muted"></p>
        <p data-confirm-error class="mt-3 hidden text-sm text-danger" role="alert"></p>
        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" data-dialog-close class="app-dialog-secondary">{{ __('Cancel') }}</button>
            <button type="button" data-confirm-action class="app-dialog-primary">{{ __('Confirm') }}</button>
        </div>
    </dialog>

    <flux:modal name="create-clinic" class="w-full max-w-lg">
        <form wire:submit="createClinic" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Create Clinic') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Add clinic details and optionally create its administrator.') }}</flux:text>
            </div>

            @if ($errors->any())
                <flux:callout variant="danger" icon="x-circle" heading="{{ __('Please review the highlighted fields.') }}">
                    <ul class="mt-2 list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </flux:callout>
            @endif

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Clinic Name') }} <span class="text-danger" aria-hidden="true">*</span></flux:label>
                    <flux:input wire:model="clinicName" required />
                    <flux:error name="clinicName" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Phone') }}</flux:label>
                    <flux:input wire:model="clinicPhone" type="tel" />
                    <flux:error name="clinicPhone" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('City') }}</flux:label>
                    <flux:input wire:model="clinicCity" />
                    <flux:error name="clinicCity" />
                </flux:field>
            </div>

            <flux:checkbox
                wire:model.live="createAdmin"
                :label="__('Create initial clinic administrator')"
            />

            @if ($createAdmin)
                <div class="space-y-4 border-t border-app-border pt-4">
                    <flux:heading size="sm">{{ __('Administrator account') }}</flux:heading>
                    <flux:field>
                        <flux:label>{{ __('Admin Name') }} <span class="text-danger" aria-hidden="true">*</span></flux:label>
                        <flux:input wire:model="adminName" required />
                        <flux:error name="adminName" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Admin Email') }} <span class="text-danger" aria-hidden="true">*</span></flux:label>
                        <flux:input wire:model="adminEmail" type="email" required />
                        <flux:error name="adminEmail" />
                    </flux:field>

                    <x-password-input
                        model="adminPassword"
                        :label="__('Admin Password')"
                        generate-action="generateAdminPassword"
                    />
                    <flux:text size="sm">
                        {{ __('Use at least 12 characters with uppercase, lowercase, numbers, and symbols.') }}
                    </flux:text>
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Create Clinic') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
