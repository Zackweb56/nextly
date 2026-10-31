<?php

declare(strict_types=1);

use App\Models\Clinic;
use App\Models\Role;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\PermissionRegistrar;

new #[Title('Utilisateurs')] class extends Component
{
    use WithPagination;

    #[Locked]
    public int $clinicId;

    public string $search = '';

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $role = '';

    #[Locked]
    public ?int $userToDeleteId = null;

    public ?string $userToDeleteName = null;

    public function mount(Clinic $clinic): void
    {
        $this->clinicId = $clinic->id;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function clinic(): Clinic
    {
        return Clinic::query()->findOrFail($this->clinicId);
    }

    /**
     * @return Collection<int, Role>
     */
    #[Computed]
    public function availableRoles(): Collection
    {
        return Role::query()
            ->where(function ($query): void {
                $query->whereNull('clinic_id')
                    ->where('name', 'clinic_admin');
            })
            ->orWhere('clinic_id', $this->clinicId)
            ->where('name', '!=', 'platform_owner')
            ->orderBy('name')
            ->get();
    }

    public function openCreateModal(): void
    {
        abort_unless(auth()->user()->hasPermissionTo('users.create'), 403);

        $this->reset(['name', 'email', 'role']);
        $this->password = Str::password(24, symbols: true);
        // $firstRole = $this->availableRoles->first();
        // $this->role = $firstRole ? $firstRole->name : '';
        $this->resetValidation();
        Flux::modal('create-user')->show();
    }

    public function generatePassword(): void
    {
        $this->password = Str::password(24, symbols: true);
    }

    public function createUser(): void
    {
        abort_unless(auth()->user()->hasPermissionTo('users.create'), 403);

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in($this->availableRoles->pluck('name')->all())],
        ]);

        $previousTeamId = getPermissionsTeamId();

        try {
            setPermissionsTeamId($this->clinicId);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $user = User::create([
                'name' => $this->name,
                'email' => $this->email,
                'password' => $this->password,
                'email_verified_at' => now(),
            ]);

            $user->clinics()->attach($this->clinicId, [
                'role' => $this->role,
                'is_active' => true,
                'joined_at' => now(),
            ]);

            $user->assignRole($this->role);

            Flux::modal('create-user')->close();
            Flux::toast(
                text: __('Utilisateur créé avec succès.'),
                heading: __('Succès'),
                variant: 'success',
            );

            $this->reset(['name', 'email', 'password', 'role']);
        } finally {
            setPermissionsTeamId($previousTeamId);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function confirmDelete(int $userId): void
    {
        abort_unless(auth()->user()->hasPermissionTo('users.delete'), 403);

        if ($userId === (int) Auth::id()) {
            throw ValidationException::withMessages([
                'user' => __('Vous ne pouvez pas supprimer votre propre compte.'),
            ]);
        }

        $user = User::query()->findOrFail($userId);

        $previousTeamId = getPermissionsTeamId();

        try {
            setPermissionsTeamId($this->clinicId);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            if ($user->hasRole('clinic_admin')) {
                throw ValidationException::withMessages([
                    'user' => __('Vous ne pouvez pas supprimer un administrateur de la clinique.'),
                ]);
            }
        } finally {
            setPermissionsTeamId($previousTeamId);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $this->userToDeleteId = $user->id;
        $this->userToDeleteName = $user->name;
        Flux::modal('delete-user')->show();
    }

    public function deleteUser(?int $userId = null): void
    {
        abort_unless(auth()->user()->hasPermissionTo('users.delete'), 403);

        $id = $userId ?? $this->userToDeleteId;

        if (! $id) {
            return;
        }

        if ($id === (int) Auth::id()) {
            throw ValidationException::withMessages([
                'user' => __('Vous ne pouvez pas supprimer votre propre compte.'),
            ]);
        }

        $user = User::query()->findOrFail($id);

        $previousTeamId = getPermissionsTeamId();

        try {
            setPermissionsTeamId($this->clinicId);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            if ($user->hasRole('clinic_admin')) {
                throw ValidationException::withMessages([
                    'user' => __('Vous ne pouvez pas supprimer un administrateur de la clinique.'),
                ]);
            }

            $user->syncRoles([]);
            $user->clinics()->detach($this->clinicId);

            if ($user->clinics()->count() === 0 && ! $user->is_platform_owner) {
                $user->delete();
            }

            Flux::modal('delete-user')->close();
            Flux::toast(
                text: __('Utilisateur supprimé avec succès.'),
                heading: __('Succès'),
                variant: 'success',
            );

            $this->reset(['userToDeleteId', 'userToDeleteName']);
        } finally {
            setPermissionsTeamId($previousTeamId);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $query = $this->clinic->users()
            ->where('users.id', '!=', Auth::id());

        if ($this->search !== '') {
            $like = '%'.$this->search.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('users.name', 'like', $like)
                    ->orWhere('users.email', 'like', $like);
            });
        }

        return $query->orderBy('users.name')->paginate(10);
    }
}; ?>

<section class="flex flex-col gap-6">
    <flux:card class="rounded-2xl p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Utilisateurs de la clinique') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Gérez les membres du personnel et leurs attributions de rôles.') }}</flux:text>
            </div>

            @if (auth()->user()->hasPermissionTo('users.create'))
                <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                    {{ __('Créer un utilisateur') }}
                </flux:button>
            @endif
        </div>
    </flux:card>

    <flux:card class="space-y-4 overflow-hidden rounded-2xl p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="lg">{{ __('Tous les utilisateurs') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Liste de tous les membres enregistrés dans la clinique.') }}</flux:text>
            </div>

            <div class="w-full sm:w-72">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Rechercher un utilisateur...') }}"
                    icon="magnifying-glass"
                    clearable
                />
            </div>
        </div>

        <flux:table :paginate="$this->users">
            <flux:table.columns>
                <flux:table.column>{{ __('Nom') }}</flux:table.column>
                <flux:table.column>{{ __('E-mail') }}</flux:table.column>
                <flux:table.column>{{ __('Rôle') }}</flux:table.column>
                <flux:table.column>{{ __('Statut') }}</flux:table.column>
                <flux:table.column>{{ __('Rejoint le') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->users as $user)
                    <flux:table.row :key="$user->id">
                        <flux:table.cell variant="strong">
                            <div class="flex items-center gap-2">
                                <flux:avatar :name="$user->name" :initials="$user->initials()" size="xs" />
                                <span>{{ $user->name }}</span>
                            </div>
                        </flux:table.cell>

                        <flux:table.cell class="text-app-muted">
                            {{ $user->email }}
                        </flux:table.cell>

                        <flux:table.cell>
                            @php
                                $roleNames = $user->roles->pluck('name')->all();
                                $displayedRole = ! empty($roleNames) ? implode(', ', $roleNames) : ($user->pivot->role ?? '—');
                            @endphp
                            <span>{{ $displayedRole }}</span>
                        </flux:table.cell>

                        <flux:table.cell>
                            <span class="app-badge app-badge-{{ $user->pivot->is_active ? 'success' : 'danger' }}">
                                {{ $user->pivot->is_active ? __('Active') : __('Inactive') }}
                            </span>
                        </flux:table.cell>

                        <flux:table.cell class="text-app-muted">
                            {{ $user->pivot->joined_at ? \Illuminate\Support\Carbon::parse($user->pivot->joined_at)->format('d/m/Y H:i') : '—' }}
                        </flux:table.cell>

                        <flux:table.cell class="py-0">
                            @if (auth()->user()->hasPermissionTo('users.delete'))
                                @if (! $user->hasRole('clinic_admin'))
                                    <flux:dropdown>
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal"></flux:button>
                                        <flux:menu>
                                            <flux:menu.item wire:click="confirmDelete({{ $user->id }})" variant="danger" icon="trash">
                                                {{ __('Supprimer') }}
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                @endif
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" class="text-center text-app-muted py-8">
                            {{ __('Aucun utilisateur trouvé.') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    {{-- Create User Modal --}}
    <flux:modal name="create-user" class="w-full max-w-lg">
        <form wire:submit="createUser" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Créer un utilisateur') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Ajoutez un nouveau membre au personnel de la clinique.') }}</flux:text>
            </div>

            @if ($errors->any())
                <flux:callout variant="danger" icon="x-circle" heading="{{ __('Veuillez vérifier les champs indiqués.') }}">
                    <ul class="mt-2 list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </flux:callout>
            @endif

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Nom complet') }} <span class="text-danger" aria-hidden="true">*</span></flux:label>
                    <flux:input wire:model="name" placeholder="{{ __('Ex: Dr. Sarah Mansouri') }}" required />
                    <flux:error name="name" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Adresse e-mail') }} <span class="text-danger" aria-hidden="true">*</span></flux:label>
                    <flux:input wire:model="email" type="email" placeholder="{{ __('sarah@example.com') }}" required />
                    <flux:error name="email" />
                </flux:field>

                <x-password-input
                    model="password"
                    :label="__('Mot de passe')"
                    generate-action="generatePassword"
                />
                <flux:text size="sm">
                    {{ __('Utilisez au moins 12 caractères avec des majuscules, des minuscules, des chiffres et des symboles.') }}
                </flux:text>

                <flux:field>
                    <flux:label>{{ __('Rôle') }} <span class="text-danger" aria-hidden="true">*</span></flux:label>
                    <flux:select wire:model="role" placeholder="{{ __('Sélectionnez un rôle') }}">
                        @foreach ($this->availableRoles as $availableRole)
                            <flux:select.option :value="$availableRole->name">
                                {{ $availableRole->name }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="role" />
                </flux:field>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Annuler') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Créer') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete User Confirmation Modal --}}
    <flux:modal name="delete-user" class="w-full max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Supprimer l\'utilisateur') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('Êtes-vous sûr de vouloir supprimer l\'utilisateur :name ? Cette action est irréversible.', ['name' => $userToDeleteName]) }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Annuler') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="deleteUser">{{ __('Supprimer') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
