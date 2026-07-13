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
        Schema::table('source_pages', function (Blueprint $table): void {
            $table->unsignedInteger('metadata_parser_version')->default(0);
            $table->unsignedInteger('metadata_attempted_version')->default(0);
            $table->timestamp('metadata_parsed_at')->nullable();
            $table->json('metadata_presence')->nullable();
            $table->index(
                ['page_type', 'metadata_parser_version', 'metadata_attempted_version', 'id'],
                'source_pages_metadata_queue_idx',
            );
        });

        Schema::table('catalog_titles', function (Blueprint $table): void {
            $table->unsignedInteger('relation_metadata_version')->default(0);
            $table->index(
                ['relation_metadata_version', 'id'],
                'catalog_titles_metadata_queue_idx',
            );
        });

        Schema::table('source_page_snapshots', function (Blueprint $table): void {
            $table->index(
                ['source_page_id', 'captured_at', 'id'],
                'source_page_snapshots_latest_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('source_page_snapshots', function (Blueprint $table): void {
            $table->dropIndex('source_page_snapshots_latest_idx');
        });

        Schema::table('catalog_titles', function (Blueprint $table): void {
            $table->dropIndex('catalog_titles_metadata_queue_idx');
            $table->dropColumn('relation_metadata_version');
        });

        Schema::table('source_pages', function (Blueprint $table): void {
            $table->dropIndex('source_pages_metadata_queue_idx');
            $table->dropColumn([
                'metadata_parser_version',
                'metadata_attempted_version',
                'metadata_parsed_at',
                'metadata_presence',
            ]);
        });
    }
};
