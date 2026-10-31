<?php

declare(strict_types=1);

use App\Models\Clinic;
use App\Models\Permission;
use App\Models\Role;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Rôles et Permissions')] class extends Component
{
    #[Locked]
    public int $clinicId;

    public string $search = '';

    public bool $isEditing = false;

    #[Locked]
    public ?int $editingRoleId = null;

    public string $roleName = '';

    /**
     * @var list<string>
     */
    public array $selectedPermissions = [];

    #[Locked]
    public ?int $roleToDeleteId = null;

    public ?string $roleToDeleteName = null;

    public function mount(Clinic $clinic): void
    {
        $this->clinicId = $clinic->id;
    }

    #[Computed]
    public function clinic(): Clinic
    {
        return Clinic::query()->findOrFail($this->clinicId);
    }

    /**
     * @return \Illuminate\Support\Collection<string, Collection<int, Permission>>
     */
    #[Computed]
    public function permissionsByGroup(): \Illuminate\Support\Collection
    {
        return Permission::query()
            ->orderBy('group')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Permission $perm): string => $perm->group ?? 'general');
    }

    /**
     * @return Collection<int, Role>
     */
    #[Computed]
    public function roles(): Collection
    {
        $query = Role::query()
            ->where(function ($query): void {
                $query->whereNull('clinic_id')
                    ->where('name', 'clinic_admin');
            })
            ->orWhere('clinic_id', $this->clinicId)
            ->where('name', '!=', 'platform_owner')
            ->with('permissions');

        if ($this->search !== '') {
            $like = '%'.$this->search.'%';
            $query->where('name', 'like', $like);
        }

        return $query->orderByDesc('is_system')
            ->orderBy('name')
            ->get();
    }

    public function getUserCountForRole(Role $role): int
    {
        $modelHasRolesTable = config('permission.table_names.model_has_roles', 'model_has_roles');
        $rolePivotKey = config('permission.column_names.role_pivot_key') ?: 'role_id';
        $teamForeignKey = config('permission.column_names.team_foreign_key') ?: 'clinic_id';

        return DB::table($modelHasRolesTable)
            ->where($rolePivotKey, $role->id)
            ->where($teamForeignKey, $this->clinicId)
            ->count();
    }

    public function openCreateModal(): void
    {
        abort_unless(auth()->user()->hasPermissionTo('roles.manage'), 403);

        $this->reset(['editingRoleId', 'roleName', 'selectedPermissions']);
        $this->isEditing = false;
        $this->resetValidation();
        Flux::modal('role-modal')->show();
    }

    public function openEditModal(int $roleId): void
    {
        abort_unless(auth()->user()->hasPermissionTo('roles.manage'), 403);

        $role = Role::query()->findOrFail($roleId);

        if ($role->is_system || $role->name === 'clinic_admin') {
            throw ValidationException::withMessages([
                'role' => __('Le rôle clinic_admin est un rôle système et ne peut pas être modifié.'),
            ]);
        }

        if ($role->clinic_id !== $this->clinicId) {
            abort(403);
        }

        $this->isEditing = true;
        $this->editingRoleId = $role->id;
        $this->roleName = $role->name;
        $this->selectedPermissions = $role->permissions->pluck('name')->all();
        $this->resetValidation();
        Flux::modal('role-modal')->show();
    }

    public function toggleGroup(string $group): void
    {
        $groupPermissions = Permission::query()->where('group', $group)->pluck('name')->all();

        if (empty($groupPermissions)) {
            return;
        }

        // Check if all permissions in this group are currently selected
        $allSelected = true;
        foreach ($groupPermissions as $perm) {
            if (! in_array($perm, $this->selectedPermissions, true)) {
                $allSelected = false;
                break;
            }
        }

        if ($allSelected) {
            // If all are selected, remove them all
            $this->selectedPermissions = array_values(array_diff($this->selectedPermissions, $groupPermissions));
        } else {
            // If not all are selected, add the missing ones
            $this->selectedPermissions = array_values(array_unique(array_merge($this->selectedPermissions, $groupPermissions)));
        }

        // Force Livewire to re-evaluate the checkbox state
        $this->dispatch('$refresh');
    }

    public function isGroupFullySelected(string $group): bool
    {
        $groupPermissions = Permission::query()->where('group', $group)->pluck('name')->all();

        if (empty($groupPermissions)) {
            return false;
        }

        foreach ($groupPermissions as $perm) {
            if (! in_array($perm, $this->selectedPermissions, true)) {
                return false;
            }
        }

        return true;
    }

    public function saveRole(): void
    {
        abort_unless(auth()->user()->hasPermissionTo('roles.manage'), 403);

        $nameRule = Rule::unique('roles', 'name')
            ->where(fn ($q) => $q->where('clinic_id', $this->clinicId));

        if ($this->isEditing && $this->editingRoleId) {
            $nameRule = $nameRule->ignore($this->editingRoleId);
        }

        $this->validate([
            'roleName' => [
                'required',
                'string',
                'max:255',
                $nameRule,
                Rule::notIn(['clinic_admin', 'platform_owner']),
            ],
            'selectedPermissions' => ['array'],
            'selectedPermissions.*' => ['string', 'exists:permissions,name'],
        ]);

        if ($this->isEditing && $this->editingRoleId) {
            $role = Role::query()->findOrFail($this->editingRoleId);

            if ($role->is_system || $role->name === 'clinic_admin') {
                throw ValidationException::withMessages([
                    'role' => __('Le rôle clinic_admin est un rôle système et ne peut pas être modifié.'),
                ]);
            }

            if ($role->clinic_id !== $this->clinicId) {
                abort(403);
            }

            $role->update([
                'name' => $this->roleName,
            ]);

            $role->syncPermissions($this->selectedPermissions);

            Flux::modal('role-modal')->close();
            Flux::toast(
                text: __('Rôle modifié avec succès.'),
                heading: __('Succès'),
                variant: 'success',
            );
        } else {
            $role = Role::create([
                'name' => $this->roleName,
                'guard_name' => 'web',
                'clinic_id' => $this->clinicId,
                'is_system' => false,
            ]);

            $role->syncPermissions($this->selectedPermissions);

            Flux::modal('role-modal')->close();
            Flux::toast(
                text: __('Rôle créé avec succès.'),
                heading: __('Succès'),
                variant: 'success',
            );
        }

        $this->reset(['isEditing', 'editingRoleId', 'roleName', 'selectedPermissions']);
    }

    public function confirmDelete(int $roleId): void
    {
        abort_unless(auth()->user()->hasPermissionTo('roles.manage'), 403);

        $role = Role::query()->findOrFail($roleId);

        if ($role->is_system || $role->name === 'clinic_admin') {
            throw ValidationException::withMessages([
                'role' => __('Le rôle clinic_admin est un rôle système et ne peut pas être supprimé.'),
            ]);
        }

        if ($role->clinic_id !== $this->clinicId) {
            abort(403);
        }

        $assignedUsersCount = $this->getUserCountForRole($role);

        if ($assignedUsersCount > 0) {
            throw ValidationException::withMessages([
                'role' => __('Ce rôle est attribué à des utilisateurs et ne peut pas être supprimé.'),
            ]);
        }

        $this->roleToDeleteId = $role->id;
        $this->roleToDeleteName = $role->name;
        Flux::modal('delete-role')->show();
    }

    public function deleteRole(?int $roleId = null): void
    {
        abort_unless(auth()->user()->hasPermissionTo('roles.manage'), 403);

        $id = $roleId ?? $this->roleToDeleteId;

        if (! $id) {
            return;
        }

        $role = Role::query()->findOrFail($id);

        if ($role->is_system || $role->name === 'clinic_admin') {
            throw ValidationException::withMessages([
                'role' => __('Le rôle clinic_admin est un rôle système et ne peut pas être supprimé.'),
            ]);
        }

        if ($role->clinic_id !== $this->clinicId) {
            abort(403);
        }

        $assignedUsersCount = $this->getUserCountForRole($role);

        if ($assignedUsersCount > 0) {
            throw ValidationException::withMessages([
                'role' => __('Ce rôle est attribué à des utilisateurs et ne peut pas être supprimé.'),
            ]);
        }

        $role->syncPermissions([]);
        $role->delete();

        Flux::modal('delete-role')->close();
        Flux::toast(
            text: __('Rôle supprimé avec succès.'),
            heading: __('Succès'),
            variant: 'success',
        );

        $this->reset(['roleToDeleteId', 'roleToDeleteName']);
    }
}; ?>

<section class="flex flex-col gap-6">
    <flux:card class="rounded-2xl p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Rôles et Permissions') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Gérez les rôles personnalisés de votre clinique et leurs permissions.') }}</flux:text>
            </div>

            @if (auth()->user()->hasPermissionTo('roles.manage'))
                <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                    {{ __('Créer un rôle') }}
                </flux:button>
            @endif
        </div>
    </flux:card>

    <flux:card class="space-y-4 overflow-hidden rounded-2xl p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="lg">{{ __('Tous les rôles') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Vue d\'ensemble des rôles personnalisés et système.') }}</flux:text>
            </div>

            <div class="w-full sm:w-72">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Rechercher un rôle...') }}"
                    icon="magnifying-glass"
                    clearable
                />
            </div>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Nom du rôle') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Utilisateurs assignés') }}</flux:table.column>
                <flux:table.column>{{ __('Permissions') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->roles as $role)
                    @php
                        $userCount = $this->getUserCountForRole($role);
                    @endphp
                    <flux:table.row :key="$role->id">
                        <flux:table.cell variant="strong">
                            <span>{{ $role->name }}</span>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($role->is_system || $role->name === 'clinic_admin')
                                <span class="app-badge app-badge-neutral">{{ __('Système') }}</span>
                            @else
                                <span class="app-badge app-badge-info">{{ __('Personnalisé') }}</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="text-app-muted">
                            {{ $userCount }} {{ trans_choice('utilisateur|utilisateurs', $userCount) }}
                        </flux:table.cell>

                        <flux:table.cell class="text-app-muted">
                            {{ $role->permissions->count() }} {{ trans_choice('permission|permissions', $role->permissions->count()) }}
                        </flux:table.cell>

                        <flux:table.cell class="py-0">
                            @if (auth()->user()->hasPermissionTo('roles.manage'))
                                @if (! ($role->is_system || $role->name === 'clinic_admin'))
                                    <flux:dropdown>
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal"></flux:button>
                                        <flux:menu>
                                            <flux:menu.item wire:click="openEditModal({{ $role->id }})" icon="pencil-square">
                                                {{ __('Modifier') }}
                                            </flux:menu.item>
                                            <flux:menu.item wire:click="confirmDelete({{ $role->id }})" variant="danger" icon="trash">
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
                        <flux:table.cell colspan="5" class="text-center text-app-muted py-8">
                            {{ __('Aucun rôle trouvé.') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    {{-- Create / Edit Role Modal --}}
    <flux:modal name="role-modal" class="w-full max-w-3xl">
        <form wire:submit="saveRole" class="flex flex-col max-h-[85vh]">
            
            {{-- Modal Header --}}
            <div class="p-6 pb-4 shrink-0 border-b border-zinc-100 dark:border-zinc-800/80">
                <flux:heading size="lg">{{ $isEditing ? __('Modifier le rôle') : __('Créer un rôle') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Définissez le nom du rôle et ses permissions d\'accès.') }}</flux:text>
            </div>

            {{-- Modal Body (Scrollable) --}}
            <div class="flex-1 overflow-y-auto p-6 space-y-6">
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
                        <flux:label>{{ __('Nom du rôle') }} <span class="text-danger" aria-hidden="true">*</span></flux:label>
                        <flux:input
                            wire:model="roleName"
                            placeholder="{{ __('Ex: Réceptionniste, Infirmier, Médecin...') }}"
                            required
                        />
                        <flux:error name="roleName" />
                    </flux:field>

                    <div class="space-y-4 pt-4">
                        <div class="flex items-center justify-between">
                            <flux:heading size="sm">{{ __('Permissions') }}</flux:heading>
                            <span class="text-xs text-zinc-500">
                                {{ count($selectedPermissions) }} {{ __('sélectionnée(s)') }}
                            </span>
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            @foreach ($this->permissionsByGroup as $group => $groupPermissions)
                                @php
                                    $isFullySelected = $this->isGroupFullySelected($group);
                                @endphp
                                
                                {{-- ADD THE wire:key HERE --}}
                                <div 
                                    wire:key="group-{{ $group }}-{{ $isFullySelected ? 'all' : 'partial' }}" 
                                    class="rounded-xl border border-zinc-200 dark:border-zinc-800 p-4 space-y-3 bg-zinc-50 dark:bg-zinc-900/50"
                                >
                                    
                                    {{-- Group Header --}}
                                    <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-3">
                                        <div class="flex items-center gap-2 cursor-pointer" wire:click="toggleGroup('{{ $group }}')">
                                            <div class="pointer-events-none">
                                                <flux:checkbox 
                                                    :checked="$isFullySelected"
                                                />
                                            </div>
                                            <span class="font-semibold text-sm">
                                                {{ __(ucfirst($group)) }}
                                            </span>
                                        </div>
                                    </div>

                                    {{-- Individual Permissions --}}
                                    <div class="grid grid-cols-1 gap-2 pt-1">
                                        @foreach ($groupPermissions as $permission)
                                            <flux:checkbox
                                                wire:model.live="selectedPermissions"
                                                value="{{ $permission->name }}"
                                                :label="__(str_replace(['_', '.'], ' ', $permission->name))"
                                            />
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            {{-- Modal Footer (Sticky) --}}
            <div class="p-4 shrink-0 flex justify-end gap-3 rounded-b-2xl">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Annuler') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">
                    {{ $isEditing ? __('Enregistrer les modifications') : __('Créer le rôle') }}
                </flux:button>
            </div>
            
        </form>
    </flux:modal>

    {{-- Delete Role Confirmation Modal --}}
    <flux:modal name="delete-role" class="w-full max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Supprimer le rôle') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('Êtes-vous sûr de vouloir supprimer le rôle :name ? Cette action est irréversible.', ['name' => $roleToDeleteName]) }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Annuler') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="deleteRole">{{ __('Supprimer') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
