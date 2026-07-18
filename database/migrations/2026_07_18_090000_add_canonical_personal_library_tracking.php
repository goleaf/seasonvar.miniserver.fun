<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episode_view_progress', function (Blueprint $table): void {
            $table->string('completion_source', 16)->nullable();
        });

        Schema::create('episode_playback_markers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->foreignId('episode_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position_seconds');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'episode_id'], 'episode_marker_user_episode_unique');
            $table->index(['user_id', 'updated_at', 'id'], 'episode_marker_user_recent_idx');
            $table->index(['catalog_title_id', 'episode_id', 'user_id'], 'episode_marker_title_episode_owner_idx');
        });

        Schema::create('catalog_title_update_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('acknowledged_release_id')->default(0);
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'catalog_title_id'], 'catalog_update_state_user_title_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_title_update_states');
        Schema::dropIfExists('episode_playback_markers');

        Schema::table('episode_view_progress', function (Blueprint $table): void {
            $table->dropColumn('completion_source');
        });
    }
};
