<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_title_user_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->boolean('in_watchlist')->default(false);
            $table->unsignedTinyInteger('rating')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'catalog_title_id'], 'catalog_user_state_user_title_unique');
            $table->index(['user_id', 'in_watchlist', 'updated_at'], 'catalog_user_state_watchlist_idx');
        });

        Schema::create('episode_view_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->foreignId('episode_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position_seconds')->default(0);
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_watched_at');
            $table->timestamps();

            $table->unique(['user_id', 'episode_id'], 'episode_progress_user_episode_unique');
            $table->index(['user_id', 'catalog_title_id', 'last_watched_at'], 'episode_progress_user_title_recent_idx');
            $table->index(['catalog_title_id', 'completed_at'], 'episode_progress_title_completion_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episode_view_progress');
        Schema::dropIfExists('catalog_title_user_states');
    }
};
