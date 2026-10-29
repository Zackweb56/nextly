@props([
    'model',
    'label' => __('Password'),
    'generateAction',
])

<div class="flex items-end gap-2">
    <div class="min-w-0 flex-1">
        <flux:field>
            <flux:label>{{ $label }} <span class="text-danger" aria-hidden="true">*</span></flux:label>
            <flux:input wire:model="{{ $model }}" type="password" viewable required />
            <flux:error name="{{ $model }}" />
        </flux:field>
    </div>

    <flux:button
        type="button"
        variant="filled"
        icon="sparkles"
        wire:click="{{ $generateAction }}"
    >
        {{ __('Generate') }}
    </flux:button>
</div>
