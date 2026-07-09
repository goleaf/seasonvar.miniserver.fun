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
        Schema::table('seasons', function (Blueprint $table) {
            $table->date('latest_episode_released_at')->nullable();
            $table->unsignedSmallInteger('episodes_released')->nullable();
            $table->unsignedSmallInteger('episodes_total')->nullable();
            $table->string('translation_name')->nullable();
            $table->string('release_status_text')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->dropColumn([
                'latest_episode_released_at',
                'episodes_released',
                'episodes_total',
                'translation_name',
                'release_status_text',
            ]);
        });
    }
};
