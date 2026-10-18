<?php

namespace App\Models;

use Database\Factories\DisplayTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisplayToken extends Model
{
    /** @use HasFactory<DisplayTokenFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'clinic_id', 'token_hash', 'label', 'last_seen_at', 'revoked_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Clinic, $this> */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }
}
