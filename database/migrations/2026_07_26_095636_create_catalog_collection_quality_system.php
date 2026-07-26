<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_collections', function (Blueprint $table): void {
            $table->unsignedTinyInteger('quality_score')->nullable();
            $table->unsignedInteger('quality_content_version')->nullable();
            $table->timestamp('quality_evaluated_at')->nullable();
            $table->char('content_signature', 64)->nullable();
            $table->char('normalized_text_hash', 64)->nullable();
            $table->json('quality_details')->nullable();
            $table->timestamp('editorially_verified_at')->nullable();
            $table->foreignId('editorially_verified_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->unsignedInteger('editorially_verified_content_version')->nullable();

            $table->index(
                ['content_signature', 'id'],
                'catalog_collections_content_signature_idx',
            );
            $table->index(
                ['quality_evaluated_at', 'id'],
                'catalog_collections_quality_refresh_idx',
            );
        });

        Schema::table('catalog_collection_items', function (Blueprint $table): void {
            $table->unsignedTinyInteger('theme_match_percent')->nullable();
            $table->string('inclusion_reason_code', 48)->nullable();
            $table->unsignedInteger('quality_content_version')->nullable();
        });

        Schema::create('catalog_collection_quality_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 24);
            $table->unsignedInteger('scanned_count')->default(0);
            $table->unsignedInteger('assessed_count')->default(0);
            $table->unsignedInteger('opened_issue_count')->default(0);
            $table->unsignedInteger('resolved_issue_count')->default(0);
            $table->string('error_message', 500)->nullable();
            $table->foreignId('started_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['status', 'started_at', 'id'],
                'catalog_collection_quality_runs_status_idx',
            );
        });

        Schema::create('catalog_collection_quality_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_collection_id')
                ->constrained('catalog_collections')
                ->cascadeOnDelete();
            $table->foreignId('related_catalog_collection_id')
                ->nullable()
                ->constrained('catalog_collections')
                ->nullOnDelete();
            $table->string('code', 64);
            $table->string('severity', 16);
            $table->string('status', 16)->default('open');
            $table->char('fingerprint', 64);
            $table->json('evidence')->nullable();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(
                'fingerprint',
                'catalog_collection_quality_issues_fingerprint_unique',
            );
            $table->index(
                ['status', 'severity', 'created_at', 'id'],
                'catalog_collection_quality_issues_queue_idx',
            );
            $table->index(
                ['catalog_collection_id', 'status', 'code', 'id'],
                'catalog_collection_quality_issues_collection_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_collection_quality_issues');
        Schema::dropIfExists('catalog_collection_quality_runs');

        Schema::table('catalog_collection_items', function (Blueprint $table): void {
            $table->dropColumn([
                'theme_match_percent',
                'inclusion_reason_code',
                'quality_content_version',
            ]);
        });

        Schema::table('catalog_collections', function (Blueprint $table): void {
            $table->dropForeign(['editorially_verified_by_id']);
            $table->dropIndex('catalog_collections_content_signature_idx');
            $table->dropIndex('catalog_collections_quality_refresh_idx');
            $table->dropColumn([
                'quality_score',
                'quality_content_version',
                'quality_evaluated_at',
                'content_signature',
                'normalized_text_hash',
                'quality_details',
                'editorially_verified_at',
                'editorially_verified_by_id',
                'editorially_verified_content_version',
            ]);
        });
    }
};
