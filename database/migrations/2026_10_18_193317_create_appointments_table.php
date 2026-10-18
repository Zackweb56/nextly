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
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();

            // Provider is the doctor. It points to the users table.
            $table->foreignId('provider_id')->nullable()->constrained('users')->nullOnDelete();

            // The soft time commitment
            $table->dateTime('scheduled_at');
            $table->integer('duration_minutes')->default(15);

            // Status flow
            $table->string('status', 20)->default('confirmed');
            // confirmed, arrived, completed, cancelled, no_show

            $table->text('notes')->nullable();

            // Who created this appointment (receptionist or admin)
            $table->foreignId('created_by')->constrained('users');

            $table->timestamps();
            $table->softDeletes();

            // Index for fast lookups on the dashboard calendar
            $table->index(['clinic_id', 'scheduled_at']);
            $table->index(['provider_id', 'scheduled_at']);

            $table->unique(['clinic_id', 'provider_id', 'scheduled_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
