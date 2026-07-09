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
        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->string('variant_type', 32)->nullable()->after('translation_name')->index();
            $table->string('variant_name', 120)->nullable()->after('variant_type');
            $table->string('variant_key', 160)->nullable()->after('variant_name')->index();
            $table->boolean('has_subtitles')->default(false)->after('variant_key')->index();

            $table->index(['episode_id', 'status', 'variant_key', 'quality'], 'licensed_media_episode_variant_quality_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->dropIndex('licensed_media_episode_variant_quality_idx');
            $table->dropIndex('licensed_media_variant_type_index');
            $table->dropIndex('licensed_media_variant_key_index');
            $table->dropIndex('licensed_media_has_subtitles_index');
            $table->dropColumn([
                'variant_type',
                'variant_name',
                'variant_key',
                'has_subtitles',
            ]);
        });
    }
};
