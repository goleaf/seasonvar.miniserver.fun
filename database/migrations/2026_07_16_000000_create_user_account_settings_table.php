<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_account_settings', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('locale', 12)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->boolean('autoplay')->nullable();
            $table->boolean('remember_volume')->nullable();
            $table->unsignedTinyInteger('volume')->nullable();
            $table->boolean('muted')->nullable();
            $table->string('playback_speed', 8)->nullable();
            $table->string('preferred_quality', 32)->nullable();
            $table->string('preferred_variant', 160)->nullable();
            $table->boolean('subtitles_enabled')->nullable();
            $table->boolean('keyboard_shortcuts_enabled')->nullable();
            $table->boolean('reduced_motion')->nullable();
            $table->string('collection_default_visibility', 24)->nullable();
            $table->unsignedInteger('settings_version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_account_settings');
    }
};
