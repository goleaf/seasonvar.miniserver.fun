<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_collection_categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('catalog_collection_categories')
                ->restrictOnDelete();
            $table->string('slug', 120)->unique();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(
                ['parent_id', 'is_active', 'position', 'id'],
                'catalog_collection_categories_tree_idx',
            );
        });

        Schema::create('catalog_collection_category_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_collection_category_id')
                ->constrained('catalog_collection_categories')
                ->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('name', 120);
            $table->timestamps();

            $table->unique(
                ['catalog_collection_category_id', 'locale'],
                'catalog_collection_category_translations_identity_unique',
            );
            $table->index(
                ['locale', 'name', 'catalog_collection_category_id'],
                'catalog_collection_category_translations_locale_name_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_collection_category_translations');
        Schema::dropIfExists('catalog_collection_categories');
    }
};
