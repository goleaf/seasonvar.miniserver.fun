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
        Schema::create('licensed_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_title_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('episode_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('storage_disk')->default('local');
            $table->string('path', 2048);
            $table->string('playback_url', 2048)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('status')->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('licensed_media');
    }
};
