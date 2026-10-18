<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('queue_tickets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();

            // Nullable because a walk-in might not be registered as a patient yet
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();

            // Nullable because this might be a pure walk-in (no appointment)
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();

            // The ticket number logic
            $table->date('queue_date'); // The day the ticket belongs to
            $table->integer('ticket_number'); // e.g., 1, 2, 3
            $table->string('ticket_code', 20); // e.g., A-001

            // Status: waiting, called, in_consultation, completed, cancelled, no_show
            $table->string('status', 20)->default('waiting');

            // Timestamps for tracking the lifecycle
            $table->timestamp('called_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Who created it, and who called it
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('called_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // A clinic cannot have two tickets with the same code on the same day
            $table->unique(['clinic_id', 'queue_date', 'ticket_code']);

            // Index for fast lookups on the TV display and receptionist dashboard
            $table->index(['clinic_id', 'queue_date', 'status']);
            $table->index(['clinic_id', 'service_id', 'queue_date', 'ticket_number'], 'qt_lookup_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('queue_tickets');
    }
};
