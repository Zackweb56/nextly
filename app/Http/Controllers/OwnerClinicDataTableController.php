<?php

namespace App\Http\Controllers;

use App\Models\Clinic;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class OwnerClinicDataTableController
{
    public function data(Request $request): JsonResponse
    {
        $draw = max(0, (int) $request->input('draw', 0));
        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);
        $length = in_array($length, [10, 25, 50], true) ? $length : 10;
        $search = Str::limit(trim((string) $request->input('search.value', '')), 100, '');

        $total = Clinic::query()->count();
        $query = Clinic::query();

        if ($search !== '') {
            $query->where(function ($query) use ($search): void {
                $like = '%'.$search.'%';

                $query
                    ->where('name', 'like', $like)
                    ->orWhere('slug', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('city', 'like', $like);
            });
        }

        $filtered = (clone $query)->count();
        $sortColumn = $this->requestedSortColumn($request);
        $sortDirection = $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc';

        match ($sortColumn) {
            'name' => $query->orderBy('name', $sortDirection),
            'city' => $query->orderBy('city', $sortDirection),
            'phone' => $query->orderBy('phone', $sortDirection),
            'subscription_status' => $query->orderBy($this->subscriptionSortQuery(), $sortDirection),
            'is_active' => $query->orderBy('is_active', $sortDirection),
            'created_at' => $query->orderBy('created_at', $sortDirection),
            default => $query->orderBy('created_at', 'desc'),
        };

        $clinics = $query
            ->skip($start)
            ->take($length)
            ->get();

        $subscriptionSummaries = $this->subscriptionSummaries($clinics->modelKeys());

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $clinics->map(function (Clinic $clinic) use ($subscriptionSummaries): array {
                $subscription = $subscriptionSummaries[$clinic->id] ?? null;

                return [
                    'id' => $clinic->id,
                    'name' => $clinic->name,
                    'slug' => $clinic->slug,
                    'city' => $clinic->city ?: '—',
                    'phone' => $clinic->phone ?: '—',
                    'subscription_status' => $subscription['status'] ?? __('No subscription'),
                    'subscription_plan' => $subscription['plan'] ?? null,
                    'is_active' => $clinic->is_active,
                    'status' => [
                        'label' => $clinic->is_active ? __('Active') : __('Suspended'),
                        'tone' => $clinic->is_active ? 'success' : 'danger',
                    ],
                    'action_label' => $clinic->is_active ? __('Suspend') : __('Activate'),
                    'action_confirm' => $clinic->is_active
                        ? __('Are you sure you want to suspend this clinic?')
                        : __('Are you sure you want to activate this clinic?'),
                    'members_title' => __('View users and permissions for :clinic', ['clinic' => $clinic->name]),
                    'members_url' => route('owner.clinics.members', $clinic),
                    'billing_url' => route('owner.clinics.show', $clinic),
                    'details_title' => __('View clinic details and billing'),
                    'created_at' => $clinic->created_at?->format('M j, Y'),
                    'status_url' => route('owner.clinics.status', $clinic),
                ];
            }),
        ]);
    }

    public function members(int $clinicId): JsonResponse
    {
        $clinic = Clinic::query()->findOrFail($clinicId);
        $previousTeamId = getPermissionsTeamId();

        try {
            setPermissionsTeamId($clinic->id);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $users = $clinic->users()
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

        return response()->json(['data' => $users]);
    }

    public function toggleStatus(int $clinicId): JsonResponse
    {
        $clinic = Clinic::query()->findOrFail($clinicId);
        $clinic->is_active = ! $clinic->is_active;
        $clinic->save();

        return response()->json([
            'is_active' => $clinic->is_active,
            'message' => $clinic->is_active
                ? __('Clinic activated successfully.')
                : __('Clinic suspended successfully.'),
        ]);
    }

    private function requestedSortColumn(Request $request): string
    {
        $columnIndex = (int) $request->input('order.0.column', -1);
        $columns = $request->input('columns', []);
        $column = is_array($columns) ? ($columns[$columnIndex]['data'] ?? '') : '';

        return in_array($column, [
            'name',
            'city',
            'phone',
            'subscription_status',
            'is_active',
            'created_at',
        ], true) ? $column : 'created_at';
    }

    private function subscriptionSortQuery(): QueryBuilder
    {
        return DB::table('subscriptions')
            ->select('status')
            ->whereColumn('subscriptions.clinic_id', 'clinics.id')
            ->orderByDesc('id')
            ->limit(1);
    }

    /**
     * @param  array<int, int>  $clinicIds
     * @return array<int, array{status: string, plan: string|null}>
     */
    private function subscriptionSummaries(array $clinicIds): array
    {
        if ($clinicIds === []) {
            return [];
        }

        $summaries = [];

        Subscription::withoutGlobalScope('clinic')
            ->with('plan')
            ->whereIn('clinic_id', $clinicIds)
            ->orderByDesc('id')
            ->get()
            ->unique('clinic_id')
            ->each(function (Subscription $subscription) use (&$summaries): void {
                $status = match ($subscription->status) {
                    'trialing' => $subscription->trial_ends_at?->isPast() === true
                        ? __('Trial expired')
                        : __('Trial'),
                    'active' => $subscription->ends_at?->isPast() === true
                        ? __('Expired')
                        : __('Active'),
                    'past_due' => __('Past due'),
                    'cancelled' => __('Cancelled'),
                    default => Str::headline($subscription->status),
                };

                $summaries[$subscription->clinic_id] = [
                    'status' => $status,
                    'plan' => $subscription->plan?->name,
                ];
            });

        return $summaries;
    }
}
