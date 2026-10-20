<?php

use App\Models\Clinic;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use App\Support\CurrentClinic;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->clinic = Clinic::factory()->create();
    $this->user = User::factory()->create();
    $this->user->clinics()->attach($this->clinic->id, [
        'role' => 'clinic_admin',
        'is_active' => true,
    ]);
    $this->actingAs($this->user);
    CurrentClinic::set($this->clinic->id);
});

it('generates a valid queue ticket', function () {
    $service = Service::factory()->create(['code' => 'A']);
    $receptionist = User::factory()->create();

    $ticket = QueueTicket::factory()->create([
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
    Service::factory()->create();

    QueueTicket::factory()->create([
        'queue_date' => '2026-10-18',
        'ticket_code' => 'A-001',
    ]);

    // This should throw an error because A-001 already exists for this clinic today
    expect(fn () => QueueTicket::factory()->create([
        'queue_date' => '2026-10-18',
        'ticket_code' => 'A-001',
    ]))->toThrow(QueryException::class);
});
