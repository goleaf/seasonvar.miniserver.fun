<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seasonvar_import_title_groups', function (Blueprint $table): void {
            $table->string('terminal_reason_code', 64)->nullable();
            $table->index(
                ['status', 'updated_at', 'id'],
                'seasonvar_import_title_groups_watchdog_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('seasonvar_import_title_groups', function (Blueprint $table): void {
            $table->dropIndex('seasonvar_import_title_groups_watchdog_idx');
            $table->dropColumn('terminal_reason_code');
        });
    }
};
