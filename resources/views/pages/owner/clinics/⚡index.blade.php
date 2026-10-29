<?php

use App\Actions\CreateClinicAction;
use App\Models\Clinic;
use App\Models\Subscription;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\PermissionRegistrar;

new #[Title('Clinics')] class extends Component {
    use WithPagination;

    public string $search = '';

    public string $sortBy = 'created_at';

    public string $sortDirection = 'desc';

    public ?int $selectedClinicIdForMembers = null;

    public ?string $selectedClinicNameForMembers = null;

    /**
     * @var array<int, array{name: string, email: string, roles: array<int, string>, permissions: array<int, string>}>
     */
    public array $membersList = [];

    public ?int $clinicIdToToggle = null;

    public ?string $clinicNameToToggle = null;

    public bool $clinicCurrentStatusToToggle = true;

    public string $clinicName = '';

    public string $clinicPhone = '';

    public string $clinicCity = '';

    public bool $createAdmin = true;

    public string $adminName = '';

    public string $adminEmail = '';

    public string $adminPassword = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function generateAdminPassword(): void
    {
        $this->adminPassword = Str::password(24, symbols: true);
    }

    #[Computed]
    public function clinics(): LengthAwarePaginator
    {
        $query = Clinic::query();

        if ($this->search !== '') {
            $like = '%'.$this->search.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('name', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            });
        }

        match ($this->sortBy) {
            'name' => $query->orderBy('name', $this->sortDirection),
            'city' => $query->orderBy('city', $this->sortDirection),
            'phone' => $query->orderBy('phone', $this->sortDirection),
            'is_active' => $query->orderBy('is_active', $this->sortDirection),
            'created_at' => $query->orderBy('created_at', $this->sortDirection),
            default => $query->orderBy('created_at', 'desc'),
        };

        return $query->paginate(10);
    }

    /**
     * @return array{label: string, tone: string}
     */
    public function getSubscriptionInfo(Clinic $clinic): array
    {
        $subscription = Subscription::withoutGlobalScope('clinic')
            ->with('plan')
            ->where('clinic_id', $clinic->id)
            ->latest('id')
            ->first();

        if (! $subscription) {
            return [
                'label' => __('No subscription'),
                'tone' => 'neutral',
            ];
        }

        $status = match ($subscription->status) {
            'trialing' => $subscription->trial_ends_at?->isPast() === true ? __('Trial expired') : __('Trial'),
            'active' => $subscription->ends_at?->isPast() === true ? __('Expired') : __('Active'),
            'past_due' => __('Past due'),
            'cancelled' => __('Cancelled'),
            default => Str::headline($subscription->status),
        };

        $tone = match ($subscription->status) {
            'active' => $subscription->ends_at?->isPast() === true ? 'danger' : 'success',
            'trialing' => $subscription->trial_ends_at?->isPast() === true ? 'danger' : 'warning',
            'past_due', 'cancelled' => 'danger',
            default => 'neutral',
        };

        $planName = $subscription->plan?->name;
        $label = $planName ? "{$status} - {$planName}" : $status;

        return [
            'label' => $label,
            'tone' => $tone,
        ];
    }

    public function openMembersModal(int $clinicId): void
    {
        $clinic = Clinic::query()->findOrFail($clinicId);
        $this->selectedClinicIdForMembers = $clinic->id;
        $this->selectedClinicNameForMembers = $clinic->name;

        $previousTeamId = getPermissionsTeamId();

        try {
            setPermissionsTeamId($clinic->id);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->membersList = $clinic->users()
                ->orderBy('users.name')
                ->get()
                ->map(function (User $user): array {
                    $user->unsetRelation('roles');
                    $user->unsetRelation('permissions');

                    return [
                        'name' => $user->name,
                        'email' => $user->email,
                        'roles' => $user->getRoleNames()->values()->all(),
                        'permissions' => $user->getAllPermissions()
                            ->pluck('name')
                            ->unique()
                            ->sort()
                            ->values()
                            ->all(),
                    ];
                })
                ->all();
        } finally {
            setPermissionsTeamId($previousTeamId);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        Flux::modal('clinic-members-modal')->show();
    }

    public function confirmToggleStatus(int $clinicId): void
    {
        $clinic = Clinic::query()->findOrFail($clinicId);
        $this->clinicIdToToggle = $clinic->id;
        $this->clinicNameToToggle = $clinic->name;
        $this->clinicCurrentStatusToToggle = $clinic->is_active;

        Flux::modal('confirm-clinic-status')->show();
    }

    public function toggleClinicStatus(): void
    {
        if ($this->clinicIdToToggle === null) {
            return;
        }

        $clinic = Clinic::query()->findOrFail($this->clinicIdToToggle);
        $clinic->is_active = ! $clinic->is_active;
        $clinic->save();

        Flux::modal('confirm-clinic-status')->close();
        Flux::toast(
            variant: 'success',
            text: $clinic->is_active
                ? __('Clinic activated successfully.')
                : __('Clinic suspended successfully.'),
        );

        $this->clinicIdToToggle = null;
        $this->clinicNameToToggle = null;
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
    }
}; ?>

<section class="flex flex-col gap-6">
    <flux:card class="rounded-2xl p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Clinics') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Manage clinics, staff, and subscriptions across the platform.') }}</flux:text>
            </div>

            <flux:modal.trigger name="create-clinic">
                <flux:button variant="primary" icon="plus">{{ __('Create Clinic') }}</flux:button>
            </flux:modal.trigger>
        </div>
    </flux:card>

    <flux:card class="space-y-4 overflow-hidden rounded-2xl p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="lg">{{ __('All Clinics') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Overview of all registered clinics and accounts.') }}</flux:text>
            </div>

            <div class="w-full sm:w-72">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search clinics...') }}"
                    icon="magnifying-glass"
                    clearable
                />
            </div>
        </div>

        <flux:table :paginate="$this->clinics">
            <flux:table.columns>
                <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">
                    {{ __('Clinic') }}
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'city'" :direction="$sortDirection" wire:click="sort('city')">
                    {{ __('City') }}
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'phone'" :direction="$sortDirection" wire:click="sort('phone')">
                    {{ __('Phone') }}
                </flux:table.column>
                <flux:table.column>
                    {{ __('Subscription') }}
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'is_active'" :direction="$sortDirection" wire:click="sort('is_active')">
                    {{ __('Clinic Status') }}
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'created_at'" :direction="$sortDirection" wire:click="sort('created_at')">
                    {{ __('Created') }}
                </flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->clinics as $clinic)
                    @php
                        $subInfo = $this->getSubscriptionInfo($clinic);
                    @endphp
                    <flux:table.row :key="$clinic->id">
                        <flux:table.cell variant="strong">
                            <a href="{{ route('owner.clinics.show', $clinic) }}" class="underline underline-offset-4 hover:text-accent-content" wire:navigate>
                                {{ $clinic->name }}
                            </a>
                        </flux:table.cell>
                        <flux:table.cell>{{ $clinic->city ?: '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $clinic->phone ?: '—' }}</flux:table.cell>
                        <flux:table.cell>
                            <span class="app-badge app-badge-{{ $subInfo['tone'] }}">
                                {{ $subInfo['label'] }}
                            </span>
                        </flux:table.cell>
                        <flux:table.cell>
                            <span class="app-badge app-badge-{{ $clinic->is_active ? 'success' : 'danger' }}">
                                {{ $clinic->is_active ? __('Active') : __('Suspended') }}
                            </span>
                        </flux:table.cell>
                        <flux:table.cell>{{ $clinic->created_at?->format('d/m/Y H:i') ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="py-0">
                            <flux:dropdown>
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal"></flux:button>
                                <flux:menu>
                                    <flux:menu.item :href="route('owner.clinics.show', $clinic)" icon="eye" wire:navigate>
                                        {{ __('View Details') }}
                                    </flux:menu.item>
                                    <flux:menu.item icon="users" wire:click="openMembersModal({{ $clinic->id }})">
                                        {{ __('Members') }}
                                    </flux:menu.item>
                                    <flux:menu.separator />
                                    <flux:menu.item
                                        icon="{{ $clinic->is_active ? 'pause' : 'play' }}"
                                        variant="{{ $clinic->is_active ? 'danger' : 'default' }}"
                                        wire:click="confirmToggleStatus({{ $clinic->id }})"
                                    >
                                        {{ $clinic->is_active ? __('Suspend Clinic') : __('Activate Clinic') }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7" class="py-8 text-center text-sm text-app-muted">
                            {{ __('No clinics found.') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal name="clinic-members-modal" class="w-full max-w-2xl">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ __('Users and permissions: :clinic', ['clinic' => $selectedClinicNameForMembers ?? '']) }}
                </flux:heading>
                <flux:text class="mt-1">
                    {{ trans_choice('{0} No users assigned|{1} 1 user assigned|[2,*] :count users assigned', count($membersList), ['count' => count($membersList)]) }}
                </flux:text>
            </div>

            <div class="max-h-[60vh] space-y-3 overflow-y-auto pr-1">
                @forelse ($membersList as $member)
                    <article class="app-member-card">
                        <div class="min-w-0">
                            <h3 class="truncate font-medium">{{ $member['name'] }}</h3>
                            <p class="mt-0.5 break-all text-sm text-app-muted">{{ $member['email'] }}</p>
                        </div>
                        <section class="mt-4">
                            <h4 class="app-member-section-title">{{ __('Roles') }}</h4>
                            <div class="app-member-badges">
                                @forelse ($member['roles'] as $role)
                                    <span class="app-badge app-badge-neutral">{{ $role }}</span>
                                @empty
                                    <span class="text-sm text-app-muted">{{ __('No roles assigned') }}</span>
                                @endforelse
                            </div>
                        </section>
                        <section class="mt-4">
                            <h4 class="app-member-section-title">{{ __('Permissions') }}</h4>
                            <div class="app-member-badges">
                                @forelse ($member['permissions'] as $permission)
                                    <span class="app-badge app-badge-info">{{ $permission }}</span>
                                @empty
                                    <span class="text-sm text-app-muted">{{ __('No permissions assigned') }}</span>
                                @endforelse
                            </div>
                        </section>
                    </article>
                @empty
                    <p class="py-8 text-center text-sm text-app-muted">
                        {{ __('No users are assigned to this clinic.') }}
                    </p>
                @endforelse
            </div>

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Close') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-clinic-status" class="w-full max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ $clinicCurrentStatusToToggle ? __('Suspend Clinic') : __('Activate Clinic') }}
                </flux:heading>
                <flux:text class="mt-2">
                    {{ $clinicCurrentStatusToToggle
                        ? __('Are you sure you want to suspend :clinic? Staff and doctors will be prevented from accessing the clinic.', ['clinic' => $clinicNameToToggle ?? ''])
                        : __('Are you sure you want to activate :clinic? Access will be restored for clinic staff.', ['clinic' => $clinicNameToToggle ?? ''])
                    }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button
                    variant="{{ $clinicCurrentStatusToToggle ? 'danger' : 'primary' }}"
                    wire:click="toggleClinicStatus"
                >
                    {{ $clinicCurrentStatusToToggle ? __('Suspend Clinic') : __('Activate Clinic') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

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
