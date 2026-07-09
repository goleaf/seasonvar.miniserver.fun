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
        Schema::table('licensed_media', function (Blueprint $table) {
            $table->string('source_media_key')->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->string('quality', 64)->nullable();
            $table->string('translation_name')->nullable();
            $table->string('format', 32)->nullable();
            $table->string('check_status', 32)->nullable()->index();
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->timestamp('checked_at')->nullable()->index();

            $table->unique(['catalog_title_id', 'source_media_key']);
            $table->index(['episode_id', 'status', 'quality'], 'licensed_media_episode_status_quality_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('licensed_media', function (Blueprint $table) {
            $table->dropUnique(['catalog_title_id', 'source_media_key']);
            $table->dropIndex('licensed_media_episode_status_quality_idx');
            $table->dropColumn([
                'source_media_key',
                'source_url',
                'quality',
                'translation_name',
                'format',
                'check_status',
                'last_http_status',
                'checked_at',
            ]);
        });
    }
};
