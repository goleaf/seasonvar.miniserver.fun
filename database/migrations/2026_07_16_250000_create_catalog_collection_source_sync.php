<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_collection_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('status', 24)->default('running');
            $table->json('counters')->nullable();
            $table->string('error_summary', 1000)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['provider', 'status', 'started_at', 'id'],
                'catalog_collection_sync_runs_provider_status_idx',
            );
            $table->index(
                ['provider', 'started_at', 'id'],
                'catalog_collection_sync_runs_provider_latest_idx',
            );
        });

        Schema::create('catalog_collection_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('source_key', 190);
            $table->foreignId('catalog_collection_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('source_path', 512);
            $table->string('remote_name', 160);
            $table->string('cover_source_path', 512)->nullable();
            $table->string('cover_path', 512)->nullable();
            $table->char('cover_content_hash', 64)->nullable();
            $table->char('semantic_content_hash', 64)->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('last_retry_at')->nullable();
            $table->foreignId('last_seen_run_id')
                ->nullable()
                ->constrained('catalog_collection_sync_runs')
                ->nullOnDelete();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'source_key']);
            $table->index(
                ['provider', 'last_successful_sync_at', 'id'],
                'catalog_collection_sources_provider_sync_idx',
            );
            $table->index(
                ['last_seen_run_id', 'provider', 'id'],
                'catalog_collection_sources_last_seen_run_idx',
            );
        });

        Schema::create('catalog_collection_source_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_collection_source_id')
                ->constrained('catalog_collection_sources')
                ->cascadeOnDelete();
            $table->string('source_item_key', 190);
            $table->string('source_title', 255);
            $table->string('normalized_title_key');
            $table->char('normalized_title_hash', 64);
            $table->unsignedSmallInteger('source_year')->nullable();
            $table->string('source_type', 32)->nullable();
            $table->json('countries')->nullable();
            $table->string('detail_path', 512);
            $table->char('detail_path_hash', 64);
            $table->unsignedInteger('source_page');
            $table->unsignedInteger('source_position');
            $table->string('match_status', 24)->default('unmatched');
            $table->foreignId('catalog_title_id')->nullable()->constrained()->nullOnDelete();
            $table->string('match_method', 64)->nullable();
            $table->unsignedSmallInteger('match_confidence')->nullable();
            $table->json('match_reasons')->nullable();
            $table->foreignId('last_seen_run_id')
                ->nullable()
                ->constrained('catalog_collection_sync_runs')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['catalog_collection_source_id', 'source_item_key'],
                'catalog_collection_source_items_source_identity_unique',
            );
            $table->index(
                ['catalog_collection_source_id', 'last_seen_run_id', 'source_position', 'id'],
                'catalog_collection_source_items_reconcile_idx',
            );
            $table->index(
                ['match_status', 'updated_at', 'id'],
                'catalog_collection_source_items_match_retry_idx',
            );
            $table->index(
                ['catalog_title_id', 'catalog_collection_source_id'],
                'catalog_collection_source_items_title_fanout_idx',
            );
        });

        Schema::table('catalog_title_search_documents', function (Blueprint $table): void {
            $table->index(
                ['normalized_title_key', 'catalog_title_id'],
                'catalog_search_docs_title_key_idx',
            );
            $table->index(
                ['normalized_original_title_key', 'catalog_title_id'],
                'catalog_search_docs_original_key_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('catalog_title_search_documents', function (Blueprint $table): void {
            $table->dropIndex('catalog_search_docs_title_key_idx');
            $table->dropIndex('catalog_search_docs_original_key_idx');
        });

        Schema::dropIfExists('catalog_collection_source_items');
        Schema::dropIfExists('catalog_collection_sources');
        Schema::dropIfExists('catalog_collection_sync_runs');
    }
};
