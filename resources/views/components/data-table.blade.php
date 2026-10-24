@props([
    'id',
    'columns',
    'ajaxUrl' => null,
    'rows' => [],
    'pageLength' => 10,
    'order' => [0, 'asc'],
    'searchable' => true,
])

@php
    $config = json_encode([
        'ajaxUrl' => $ajaxUrl,
        'columns' => $columns,
        'rows' => $rows,
        'pageLength' => $pageLength,
        'order' => $order,
        'searchable' => $searchable,
    ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
@endphp

<div data-data-table-component wire:ignore>
    <div class="overflow-x-auto">
        <table id="{{ $id }}" class="app-data-table w-full" data-data-table data-config="{{ $config }}">
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th>{{ $column['title'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
    <p data-table-feedback class="data-table-feedback mt-3 hidden text-sm" role="status" aria-live="polite"></p>
</div>
