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
            $table->foreignId('catalog_collection_category_id')
                ->nullable()
                ->constrained('catalog_collection_categories')
                ->restrictOnDelete();

            $table->index(
                [
                    'catalog_collection_category_id',
                    'visibility',
                    'moderation_status',
                    'deleted_at',
                    'updated_at',
                    'id',
                ],
                'catalog_collections_category_public_order_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('catalog_collections', function (Blueprint $table): void {
            $table->dropIndex('catalog_collections_category_public_order_idx');
            $table->dropConstrainedForeignId('catalog_collection_category_id');
        });
    }
};
