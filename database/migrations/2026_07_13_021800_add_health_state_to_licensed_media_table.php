<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->string('health_status', 24)->default('active')->after('check_status');
            $table->timestamp('last_successful_check_at')->nullable()->after('checked_at');
            $table->string('last_error_category', 32)->nullable()->after('last_successful_check_at');
            $table->unsignedInteger('consecutive_failures')->default(0)->after('last_error_category');
            $table->unsignedInteger('check_latency_ms')->nullable()->after('consecutive_failures');
            $table->timestamp('next_check_at')->nullable()->after('check_latency_ms');
            $table->index(
                ['health_status', 'next_check_at', 'id'],
                'licensed_media_health_due_idx',
            );
        });

        DB::table('licensed_media')
            ->where('check_status', 'available')
            ->update([
                'health_status' => 'active',
                'last_successful_check_at' => DB::raw('checked_at'),
                'next_check_at' => DB::raw('checked_at'),
            ]);

        DB::table('licensed_media')
            ->where(function ($query): void {
                $query->where('status', 'unavailable')
                    ->orWhereIn('check_status', ['check_failed', 'unavailable', 'invalid_url']);
            })
            ->update([
                'health_status' => 'unavailable',
                'last_error_category' => DB::raw("CASE check_status WHEN 'invalid_url' THEN 'invalid_url' ELSE 'unknown' END"),
                'consecutive_failures' => 1,
                'next_check_at' => DB::raw('checked_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->dropIndex('licensed_media_health_due_idx');
            $table->dropColumn([
                'health_status',
                'last_successful_check_at',
                'last_error_category',
                'consecutive_failures',
                'check_latency_ms',
                'next_check_at',
            ]);
        });
    }
};
