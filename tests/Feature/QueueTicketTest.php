<?php

use App\Models\Clinic;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\QueryException;

it('generates a valid queue ticket', function () {
    $clinic = Clinic::factory()->create();
    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'code' => 'A']);
    $receptionist = User::factory()->create();

    $ticket = QueueTicket::factory()->create([
        'clinic_id' => $clinic->id,
        'service_id' => $service->id,
        'queue_date' => '2026-10-18',
        'ticket_number' => 1,
        'ticket_code' => 'A-001',
        'created_by' => $receptionist->id,
    ]);

    expect($ticket->ticket_code)->toBe('A-001');
    expect($ticket->status)->toBe('waiting');
});

it('prevents duplicate ticket codes on the same day for the same clinic', function () {
    $clinic = Clinic::factory()->create();
    $service = Service::factory()->create(['clinic_id' => $clinic->id]);

    QueueTicket::factory()->create([
        'clinic_id' => $clinic->id,
        'queue_date' => '2026-10-18',
        'ticket_code' => 'A-001',
    ]);

    // This should throw an error because A-001 already exists for this clinic today
    expect(fn () => QueueTicket::factory()->create([
        'clinic_id' => $clinic->id,
        'queue_date' => '2026-10-18',
        'ticket_code' => 'A-001',
    ]))->toThrow(QueryException::class);
});
