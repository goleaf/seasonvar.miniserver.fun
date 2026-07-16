<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_recommendation_dirty_titles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_title_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('reason', 64);
            $table->timestamp('marked_at');
            $table->timestamps();

            $table->index(['marked_at', 'id'], 'catalog_recommendation_dirty_titles_marked_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_recommendation_dirty_titles');
    }
};
