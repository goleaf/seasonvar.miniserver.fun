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
        Schema::create('catalog_title_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recommended_title_id')->constrained('catalog_titles')->cascadeOnDelete();
            $table->unsignedInteger('score');
            $table->unsignedInteger('rank');
            $table->json('reasons')->nullable();
            $table->timestamp('computed_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['catalog_title_id', 'recommended_title_id'], 'catalog_title_recommendations_pair_unique');
            $table->index(['catalog_title_id', 'rank']);
            $table->index(['recommended_title_id', 'score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_title_recommendations');
    }
};
