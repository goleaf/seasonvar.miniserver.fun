<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_recommendation_preferences', function (Blueprint $table): void {
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->string('playback_preference', 16)->default('any');
            $table->string('completion_preference', 16)->default('any');
            $table->string('episode_length_preference', 16)->default('any');
        });

        Schema::create('catalog_recommendation_onboarding_titles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->timestamps();
            $table->unique(
                ['user_id', 'catalog_title_id'],
                'recommendation_onboarding_title_user_title_unique',
            );
            $table->index(
                ['user_id', 'kind', 'catalog_title_id'],
                'recommendation_onboarding_title_user_kind_idx',
            );
        });

        Schema::create('catalog_recommendation_preferred_genres', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('genre_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(
                ['user_id', 'genre_id'],
                'recommendation_preferred_genre_user_genre_unique',
            );
        });

        Schema::create('catalog_recommendation_preferred_countries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(
                ['user_id', 'country_id'],
                'recommendation_preferred_country_user_country_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_recommendation_preferred_countries');
        Schema::dropIfExists('catalog_recommendation_preferred_genres');
        Schema::dropIfExists('catalog_recommendation_onboarding_titles');

        Schema::table('catalog_recommendation_preferences', function (Blueprint $table): void {
            $table->dropColumn([
                'onboarding_completed_at',
                'playback_preference',
                'completion_preference',
                'episode_length_preference',
            ]);
        });
    }
};
