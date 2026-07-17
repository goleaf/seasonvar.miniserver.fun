<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_collection_sources', function (Blueprint $table): void {
            $table->timestamp('missing_since_at')->nullable()->after('last_successful_sync_at');
            $table->index(
                ['provider', 'missing_since_at', 'id'],
                'catalog_collection_sources_provider_missing_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('catalog_collection_sources', function (Blueprint $table): void {
            $table->dropIndex('catalog_collection_sources_provider_missing_idx');
            $table->dropColumn('missing_since_at');
        });
    }
};
