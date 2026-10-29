<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $period_starts_at
 * @property Carbon|null $period_ends_at
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use BelongsToClinic, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'clinic_id', 'subscription_id', 'amount', 'currency', 'status', 'method', 'reference', 'paid_at', 'period_starts_at', 'period_ends_at', 'recorded_by', 'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Clinic, $this> */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
