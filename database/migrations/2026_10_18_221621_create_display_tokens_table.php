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
        Schema::create('display_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();

            // We store the SHA-256 hash, never the raw token!
            $table->string('token_hash', 64)->unique();

            $table->string('label', 100)->default('Waiting Room TV');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('display_tokens');
    }
};
