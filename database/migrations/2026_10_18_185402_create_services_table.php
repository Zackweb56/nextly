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
        Schema::create('services', function (Blueprint $table) {
            $table->id();

            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('code', 10); // e.g., 'A', 'CONS', 'LAB'
            $table->text('description')->nullable();
            $table->integer('duration_minutes')->default(15);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0); // For custom ordering in the UI

            $table->timestamps();
            $table->softDeletes();

            // A clinic cannot have two services with the same code (e.g., two 'A's)
            $table->unique(['clinic_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
