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
        Schema::create('catalog_title_quality_snapshots', function (Blueprint $table): void {
            $table->foreignId('catalog_title_id')
                ->primary()
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('quality_score');
            $table->string('severity', 16);
            $table->unsignedSmallInteger('issue_count')->default(0);
            $table->unsignedSmallInteger('critical_count')->default(0);
            $table->boolean('needs_refresh')->default(false);
            $table->unsignedSmallInteger('scoring_version');
            $table->timestamp('last_source_checked_at')->nullable();
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->index(
                ['quality_score', 'catalog_title_id'],
                'catalog_quality_score_idx',
            );
            $table->index(
                ['severity', 'quality_score', 'catalog_title_id'],
                'catalog_quality_severity_score_idx',
            );
            $table->index(
                ['needs_refresh', 'scoring_version', 'evaluated_at', 'catalog_title_id'],
                'catalog_quality_refresh_idx',
            );
        });

        Schema::create('catalog_title_quality_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('category', 32);
            $table->string('severity', 16);
            $table->unsignedTinyInteger('penalty');
            $table->json('evidence')->nullable();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->timestamps();

            $table->unique(
                ['catalog_title_id', 'code'],
                'catalog_quality_issue_title_code_unique',
            );
            $table->index(
                ['category', 'catalog_title_id', 'severity'],
                'catalog_quality_issue_queue_idx',
            );
            $table->index(
                ['severity', 'catalog_title_id'],
                'catalog_quality_issue_severity_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_title_quality_issues');
        Schema::dropIfExists('catalog_title_quality_snapshots');
    }
};
