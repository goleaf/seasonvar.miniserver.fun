<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seasonvar_import_runs', function (Blueprint $table): void {
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('retry_of_run_id')->nullable()->constrained('seasonvar_import_runs')->nullOnDelete();
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->index(
                ['execution_mode', 'status', 'last_heartbeat_at'],
                'seasonvar_runs_mode_status_heartbeat_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('seasonvar_import_runs', function (Blueprint $table): void {
            $table->dropIndex('seasonvar_runs_mode_status_heartbeat_idx');
            $table->dropIndex(['last_heartbeat_at']);
            $table->dropConstrainedForeignId('retry_of_run_id');
            $table->dropConstrainedForeignId('requested_by_user_id');
            $table->dropColumn(['last_heartbeat_at', 'cancel_requested_at']);
        });
    }
};
