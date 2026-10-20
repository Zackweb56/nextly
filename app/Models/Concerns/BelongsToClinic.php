<?php

namespace App\Models\Concerns;

use App\Models\Clinic;
use App\Support\CurrentClinic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToClinic
{
    /**
     * Boot the trait and apply the global scope.
     */
    protected static function bootBelongsToClinic(): void
    {
        // 1. Apply the tenant scope to all queries
        static::addGlobalScope('clinic', function (Builder $builder) {
            $clinicId = CurrentClinic::id();

            if ($clinicId) {
                $builder->where($builder->getModel()->qualifyColumn('clinic_id'), $clinicId);
            } else {
                // SECURITY: If there is no current clinic (e.g., platform owner without a selected clinic),
                // we block the query entirely to prevent data leaks.
                $builder->whereRaw('1 = 0');
            }
        });

        // 2. Automatically assign the clinic_id when creating a new record
        static::creating(function ($model) {
            if (empty($model->clinic_id)) {
                $clinicId = CurrentClinic::id();
                if ($clinicId) {
                    $model->clinic_id = $clinicId;
                }
            }
        });
    }

    /**
     * Get the clinic that owns the model.
     *
     * @return BelongsTo<Clinic, $this>
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }
}
