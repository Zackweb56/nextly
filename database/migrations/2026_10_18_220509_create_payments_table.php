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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('MAD');

            $table->string('status', 20)->default('pending'); // pending, paid, failed
            $table->string('method', 30)->nullable(); // cash, bank_transfer

            $table->string('reference')->nullable(); // Bank transfer ref
            $table->timestamp('paid_at')->nullable();

            // The platform owner who recorded the payment
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
