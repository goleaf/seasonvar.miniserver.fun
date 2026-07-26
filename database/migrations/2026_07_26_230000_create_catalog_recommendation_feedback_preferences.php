<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_recommendation_feedback_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 32);
            $table->foreignId('genre_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['user_id', 'catalog_title_id'],
                'catalog_recommendation_feedback_detail_user_title_unique',
            );
            $table->index(
                ['user_id', 'updated_at', 'id'],
                'catalog_recommendation_feedback_detail_user_activity_idx',
            );
        });

        Schema::create('catalog_recommendation_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('diversity', 16)->default('balanced');
            $table->string('freshness', 16)->default('balanced');
            $table->timestamp('profile_reset_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('catalog_recommendation_hidden_genres', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('genre_id')->constrained()->cascadeOnDelete();
            $table->timestamp('hidden_until');
            $table->timestamps();

            $table->unique(
                ['user_id', 'genre_id'],
                'catalog_recommendation_hidden_genre_user_genre_unique',
            );
            $table->index(
                ['user_id', 'hidden_until', 'id'],
                'catalog_recommendation_hidden_genre_user_expiry_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_recommendation_hidden_genres');
        Schema::dropIfExists('catalog_recommendation_preferences');
        Schema::dropIfExists('catalog_recommendation_feedback_details');
    }
};
