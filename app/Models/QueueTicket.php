<?php

namespace App\Models;

use Database\Factories\QueueTicketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueTicket extends Model
{
    /** @use HasFactory<QueueTicketFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'clinic_id',
        'service_id',
        'patient_id',
        'appointment_id',
        'queue_date',
        'ticket_number',
        'ticket_code',
        'status',
        'called_at',
        'started_at',
        'completed_at',
        'cancelled_at',
        'created_by',
        'called_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'queue_date' => 'date',
            'ticket_number' => 'integer',
            'called_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Clinic, $this> */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
