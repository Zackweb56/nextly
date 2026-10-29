<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $status
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $cancelled_at
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use BelongsToClinic, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'clinic_id', 'plan_id', 'status', 'starts_at', 'ends_at', 'trial_ends_at', 'cancelled_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // /** @return BelongsTo<Clinic, $this> */
    // public function clinic(): BelongsTo
    // {
    //     return $this->belongsTo(Clinic::class);
    // }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isTrialing(): bool
    {
        return $this->status === 'trialing'
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    public function onGracePeriod(): bool
    {
        return $this->status === 'cancelled'
            && $this->ends_at !== null
            && $this->ends_at->isFuture();
    }

    public function isPastDue(): bool
    {
        if ($this->status === 'past_due') {
            return true;
        }

        if ($this->status === 'trialing' && $this->trial_ends_at?->isPast()) {
            return true;
        }

        if ($this->status === 'active' && $this->ends_at?->isPast()) {
            return true;
        }

        return false;
    }
}
