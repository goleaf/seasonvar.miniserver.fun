<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_collection_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_collection_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('seo_title', 180)->nullable();
            $table->text('seo_description')->nullable();
            $table->timestamps();

            $table->unique(['catalog_collection_id', 'locale'], 'catalog_collection_translations_collection_locale_unique');
            $table->index(['locale', 'name'], 'catalog_collection_translations_locale_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_collection_translations');
    }
};
