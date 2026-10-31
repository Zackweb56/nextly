<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $permissionsTable = $tableNames['permissions'] ?? 'permissions';

        Schema::table($permissionsTable, function (Blueprint $table): void {
            $table->string('group')->nullable()->after('guard_name');
        });
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names');
        $permissionsTable = $tableNames['permissions'] ?? 'permissions';

        Schema::table($permissionsTable, function (Blueprint $table): void {
            $table->dropColumn('group');
        });
    }
};
