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
        Schema::table('catalog_title_quality_snapshots', function (Blueprint $table): void {
            $table->foreignId('catalog_quality_run_id')
                ->nullable()
                ->constrained('catalog_quality_runs')
                ->nullOnDelete();
        });
        Schema::table('catalog_title_quality_issues', function (Blueprint $table): void {
            $table->foreignId('catalog_quality_run_id')
                ->nullable()
                ->constrained('catalog_quality_runs')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_title_quality_issues', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('catalog_quality_run_id');
        });
        Schema::table('catalog_title_quality_snapshots', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('catalog_quality_run_id');
        });
    }
};
