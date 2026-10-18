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
        Schema::table('users', function (Blueprint $table) {
            // Add the new columns after the 'email' column for better organization
            $table->string('phone', 20)->nullable()->after('email');
            $table->boolean('is_platform_owner')->default(false)->after('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop the columns if we roll back
            $table->dropColumn(['phone', 'is_platform_owner']);
        });
    }
};
