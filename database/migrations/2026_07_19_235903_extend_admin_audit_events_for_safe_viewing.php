<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_audit_events', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique();
            $table->string('resource_public_id', 191)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->index(['occurred_at', 'id'], 'admin_audit_time_id_idx');
            $table->index(['resource_type', 'resource_public_id', 'occurred_at'], 'admin_audit_public_resource_idx');
            $table->index(['correlation_id', 'occurred_at'], 'admin_audit_correlation_idx');
        });

        DB::table('admin_audit_events')->orderBy('id')->chunkById(500, function ($events): void {
            foreach ($events as $event) {
                DB::table('admin_audit_events')->where('id', $event->id)->update([
                    'public_id' => (string) Str::uuid(),
                    'resource_public_id' => hash('sha256', $event->resource_type.':'.$event->resource_id),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('admin_audit_events', function (Blueprint $table): void {
            $table->dropIndex('admin_audit_time_id_idx');
            $table->dropIndex('admin_audit_public_resource_idx');
            $table->dropIndex('admin_audit_correlation_idx');
            $table->dropUnique('admin_audit_events_public_id_unique');
            $table->dropColumn(['public_id', 'resource_public_id', 'correlation_id']);
        });
    }
};
