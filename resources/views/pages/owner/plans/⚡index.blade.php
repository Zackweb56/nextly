<?php

use App\Models\Plan;
use App\Models\Subscription;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Plans')] class extends Component {
    public ?int $planId = null;
    public string $name = '';
    public string $slug = '';
    public string $price = '';
    public string $billingInterval = 'monthly';
    public int $trialDays = 14;
    public bool $isActive = true;
    public bool $slugManuallyEdited = false;

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
        $this->price = $plan->price;
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
        $this->dispatch('plans-updated');
    }

    #[On('plan-delete-confirmed')]
    public function deletePlan(int $planId): void
    {
        $plan = Plan::query()->findOrFail($planId);

        if (Subscription::withoutGlobalScope('clinic')->where('plan_id', $plan->id)->exists()) {
            Flux::toast(
                variant: 'danger',
                text: __('This plan cannot be deleted because it has subscriptions.'),
            );

            return;
        }

        $plan->delete();

        Flux::toast(variant: 'success', text: __('Plan deleted successfully.'));
        $this->dispatch('plans-updated');
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
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Plans') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Manage subscription plans available to clinics.') }}</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
            {{ __('Create Plan') }}
        </flux:button>
    </div>

    <flux:card class="overflow-hidden">
        <x-data-table
            id="owner-plans-table"
            :ajax-url="route('owner.plans.data')"
            :columns="[
                ['data' => 'name', 'title' => __('Name'), 'render' => 'plan'],
                ['data' => 'price', 'title' => __('Price'), 'render' => 'plan-price'],
                ['data' => 'billing_interval', 'title' => __('Billing Interval')],
                ['data' => 'trial_days', 'title' => __('Trial Days')],
                ['data' => 'status', 'title' => __('Status'), 'render' => 'badge', 'orderable' => false, 'searchable' => false],
                ['data' => 'id', 'title' => __('Actions'), 'render' => 'plan-actions', 'orderable' => false, 'searchable' => false],
            ]"
            :order="[0, 'asc']"
        />
    </flux:card>

    <dialog id="plan-delete-dialog" class="app-table-dialog w-[min(28rem,calc(100%-2rem))] rounded-2xl border border-app-border bg-app-surface p-5 text-app-text shadow-2xl sm:p-6">
        <h2 class="text-lg font-semibold">{{ __('Confirm plan deletion') }}</h2>
        <p data-plan-delete-message class="mt-2 text-sm text-app-muted"></p>
        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" data-dialog-close class="app-dialog-secondary">{{ __('Cancel') }}</button>
            <button type="button" data-plan-delete-confirm class="app-dialog-primary">{{ __('Delete Plan') }}</button>
        </div>
    </dialog>

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
