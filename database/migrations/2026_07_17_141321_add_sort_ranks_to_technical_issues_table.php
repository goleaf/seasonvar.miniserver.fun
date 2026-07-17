<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_issues', function (Blueprint $table): void {
            $table->unsignedTinyInteger('severity_sort_rank')->default(2);
            $table->unsignedTinyInteger('priority_sort_rank')->default(2);
            $table->index(
                ['severity_sort_rank', 'created_at', 'id'],
                'technical_issues_severity_sort_idx',
            );
            $table->index(
                ['priority_sort_rank', 'created_at', 'id'],
                'technical_issues_priority_sort_idx',
            );
        });

        DB::table('technical_issues')->update([
            'severity_sort_rank' => DB::raw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'low' THEN 3 ELSE 2 END"),
            'priority_sort_rank' => DB::raw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'low' THEN 3 ELSE 2 END"),
        ]);
    }

    public function down(): void
    {
        Schema::table('technical_issues', function (Blueprint $table): void {
            $table->dropIndex('technical_issues_severity_sort_idx');
            $table->dropIndex('technical_issues_priority_sort_idx');
            $table->dropColumn(['severity_sort_rank', 'priority_sort_rank']);
        });
    }
};
