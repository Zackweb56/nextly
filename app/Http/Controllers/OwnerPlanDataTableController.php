<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OwnerPlanDataTableController
{
    public function data(Request $request): JsonResponse
    {
        $draw = max(0, (int) $request->input('draw', 0));
        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);
        $length = in_array($length, [10, 25, 50], true) ? $length : 10;
        $search = Str::limit(trim((string) $request->input('search.value', '')), 100, '');

        $total = Plan::query()->count();
        $query = Plan::query();

        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';

                $query
                    ->where('name', 'like', $like)
                    ->orWhere('slug', 'like', $like)
                    ->orWhere('billing_interval', 'like', $like);
            });
        }

        $filtered = (clone $query)->count();
        $sortColumn = $this->requestedSortColumn($request);
        $sortDirection = $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc';

        match ($sortColumn) {
            'name' => $query->orderBy('name', $sortDirection),
            'price' => $query->orderBy('price', $sortDirection),
            'billing_interval' => $query->orderBy('billing_interval', $sortDirection),
            'trial_days' => $query->orderBy('trial_days', $sortDirection),
            'is_active' => $query->orderBy('is_active', $sortDirection),
            default => $query->orderBy('name'),
        };

        $plans = $query
            ->skip($start)
            ->take($length)
            ->get();

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $plans->map(fn (Plan $plan): array => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'price' => $plan->price,
                'billing_interval' => $plan->billing_interval,
                'trial_days' => $plan->trial_days,
                'is_active' => $plan->is_active,
                'edit_label' => __('Edit :plan', ['plan' => $plan->name]),
                'delete_label' => __('Delete :plan', ['plan' => $plan->name]),
                'delete_confirm' => __('Are you sure you want to delete :plan? This action cannot be undone.', ['plan' => $plan->name]),
                'status' => [
                    'label' => $plan->is_active ? __('Active') : __('Inactive'),
                    'tone' => $plan->is_active ? 'success' : 'neutral',
                ],
            ]),
        ]);
    }

    private function requestedSortColumn(Request $request): string
    {
        $columnIndex = (int) $request->input('order.0.column', -1);
        $columns = $request->input('columns', []);
        $column = is_array($columns) ? ($columns[$columnIndex]['data'] ?? '') : '';

        return in_array($column, [
            'name',
            'price',
            'billing_interval',
            'trial_days',
            'is_active',
        ], true) ? $column : 'name';
    }
}
