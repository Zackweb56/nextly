<?php

use App\Models\Plan;
use App\Models\Subscription;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Plans')] class extends Component {
    use WithPagination;

    public string $search = '';

    public string $sortBy = 'name';

    public string $sortDirection = 'asc';

    public ?int $planId = null;

    public ?int $deletingPlanId = null;

    public string $name = '';

    public string $slug = '';

    public string $price = '';

    public string $billingInterval = 'monthly';

    public int $trialDays = 14;

    public bool $isActive = true;

    public bool $slugManuallyEdited = false;

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

    public function updatedName(string $name): void
    {
        if (! $this->slugManuallyEdited) {
            $this->slug = Str::slug($name);
        }
    }

    public function updatedSlug(): void
    {
        $this->slugManuallyEdited = true;
    }

    #[Computed]
    public function plans(): LengthAwarePaginator
    {
        return Plan::query()
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($q): void {
                    $q->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('slug', 'like', '%'.$this->search.'%');
                });
            })
            ->when(
                in_array($this->sortBy, ['name', 'price', 'billing_interval', 'trial_days', 'is_active'], true),
                fn ($query) => $query->orderBy($this->sortBy, $this->sortDirection),
                fn ($query) => $query->orderBy('name', 'asc')
            )
            ->paginate(10);
    }

    public function openCreateModal(): void
    {
        $this->resetForm();
        Flux::modal('plan-form')->show();
    }

    #[On('plan-edit-requested')]
    public function editPlan(int $planId): void
    {
        $plan = Plan::query()->findOrFail($planId);

        $this->planId = $plan->id;
        $this->name = $plan->name;
        $this->slug = $plan->slug;
        $this->price = (string) $plan->price;
        $this->billingInterval = $plan->billing_interval;
        $this->trialDays = $plan->trial_days;
        $this->isActive = $plan->is_active;
        $this->slugManuallyEdited = true;
        $this->resetValidation();

        Flux::modal('plan-form')->show();
    }

    public function savePlan(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('plans', 'slug')->ignore($this->planId),
            ],
            'price' => ['required', 'numeric', 'min:0'],
            'billingInterval' => ['required', Rule::in(['monthly', 'yearly'])],
            'trialDays' => ['required', 'integer', 'min:0'],
            'isActive' => ['required', 'boolean'],
        ]);

        $plan = $this->planId === null
            ? new Plan
            : Plan::query()->findOrFail($this->planId);

        $plan->fill([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'price' => $validated['price'],
            'billing_interval' => $validated['billingInterval'],
            'trial_days' => $validated['trialDays'],
            'is_active' => $validated['isActive'],
        ]);
        $plan->save();

        Flux::modal('plan-form')->close();
        Flux::toast(
            variant: 'success',
            text: $this->planId === null
                ? __('Plan created successfully.')
                : __('Plan updated successfully.'),
        );

        $this->resetForm();
    }

    public function confirmDeletePlan(int $planId): void
    {
        $this->deletingPlanId = $planId;
        Flux::modal('confirm-delete-plan')->show();
    }

    #[On('plan-delete-confirmed')]
    public function deletePlan(?int $planId = null): void
    {
        $id = $planId ?? $this->deletingPlanId;

        if ($id === null) {
            return;
        }

        $plan = Plan::query()->findOrFail($id);

        if (Subscription::withoutGlobalScope('clinic')->where('plan_id', $plan->id)->exists()) {
            Flux::modal('confirm-delete-plan')->close();
            Flux::toast(
                variant: 'danger',
                text: __('This plan cannot be deleted because it has subscriptions.'),
            );
            $this->deletingPlanId = null;

            return;
        }

        $plan->delete();
        $this->deletingPlanId = null;

        Flux::modal('confirm-delete-plan')->close();
        Flux::toast(variant: 'success', text: __('Plan deleted successfully.'));
    }

    private function resetForm(): void
    {
        $this->reset([
            'planId',
            'name',
            'slug',
            'price',
            'billingInterval',
            'trialDays',
            'isActive',
            'slugManuallyEdited',
        ]);
        $this->resetValidation();
    }
}; ?>

<section class="flex flex-col gap-6">
    <flux:card class="rounded-2xl p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Plans') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Manage subscription plans available to clinics.') }}</flux:text>
            </div>

            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                {{ __('Create Plan') }}
            </flux:button>
        </div>
    </flux:card>

    <flux:card class="space-y-4 overflow-hidden rounded-2xl p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="lg">{{ __('All Plans') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Available subscription tiers and billing models.') }}</flux:text>
            </div>

            <div class="w-full sm:w-72">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search plans...') }}"
                    icon="magnifying-glass"
                    clearable
                />
            </div>
        </div>

        <flux:table :paginate="$this->plans">
            <flux:table.columns>
                <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">
                    {{ __('Name') }}
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'price'" :direction="$sortDirection" wire:click="sort('price')">
                    {{ __('Price') }}
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'billing_interval'" :direction="$sortDirection" wire:click="sort('billing_interval')">
                    {{ __('Billing Interval') }}
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'trial_days'" :direction="$sortDirection" wire:click="sort('trial_days')">
                    {{ __('Trial Days') }}
                </flux:table.column>
                <flux:table.column>
                    {{ __('Status') }}
                </flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->plans as $plan)
                    <flux:table.row :key="$plan->id">
                        <flux:table.cell variant="strong">{{ $plan->name }}</flux:table.cell>
                        <flux:table.cell>{{ $plan->price }} MAD</flux:table.cell>
                        <flux:table.cell>{{ __(ucfirst($plan->billing_interval)) }}</flux:table.cell>
                        <flux:table.cell>{{ $plan->trial_days }}</flux:table.cell>
                        <flux:table.cell>
                            <span class="app-badge app-badge-{{ $plan->is_active ? 'success' : 'neutral' }}">
                                {{ $plan->is_active ? __('Active') : __('Inactive') }}
                            </span>
                        </flux:table.cell>
                        <flux:table.cell class="py-0">
                            <flux:dropdown>
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal"></flux:button>
                                <flux:menu>
                                    <flux:menu.item icon="pencil-square" wire:click="editPlan({{ $plan->id }})">
                                        {{ __('Edit') }}
                                    </flux:menu.item>
                                    <flux:menu.item icon="trash" variant="danger" wire:click="confirmDeletePlan({{ $plan->id }})">
                                        {{ __('Delete') }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" class="py-8 text-center text-sm text-app-muted">
                            {{ __('No plans found.') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal name="confirm-delete-plan" class="w-full max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Confirm plan deletion') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Are you sure you want to delete this plan? This action cannot be undone.') }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="deletePlan">
                    {{ __('Delete Plan') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="plan-form" class="w-full max-w-lg">
        <form wire:submit="savePlan" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ $planId === null ? __('Create Plan') : __('Edit Plan') }}
                </flux:heading>
                <flux:text class="mt-1">{{ __('Configure pricing and trial settings for this plan.') }}</flux:text>
            </div>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Name') }} <span class="text-danger" aria-hidden="true">*</span></flux:label>
                    <flux:input wire:model.live.debounce.300ms="name" required />
                    <flux:error name="name" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Slug') }} <span class="text-danger" aria-hidden="true">*</span></flux:label>
                    <flux:input wire:model="slug" required />
                    <flux:error name="slug" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Price') }} <span class="text-danger" aria-hidden="true">*</span></flux:label>
                    <flux:input wire:model="price" type="number" min="0" step="0.01" required />
                    <flux:error name="price" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Billing Interval') }} <span class="text-danger" aria-hidden="true">*</span></flux:label>
                    <flux:select wire:model="billingInterval" required>
                        <flux:select.option value="monthly">{{ __('Monthly') }}</flux:select.option>
                        <flux:select.option value="yearly">{{ __('Yearly') }}</flux:select.option>
                    </flux:select>
                    <flux:error name="billingInterval" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Trial Days') }} <span class="text-danger" aria-hidden="true">*</span></flux:label>
                    <flux:input wire:model="trialDays" type="number" min="0" required />
                    <flux:error name="trialDays" />
                </flux:field>

                <flux:switch wire:model="isActive" :label="__('Active')" />
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">
                    {{ $planId === null ? __('Create Plan') : __('Save Changes') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
