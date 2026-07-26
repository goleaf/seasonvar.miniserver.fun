<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_account_settings', function (Blueprint $table): void {
            $table->string('fallback_variant', 160)->nullable()->after('preferred_variant');
            $table->string('preferred_playback_mode', 32)->nullable()->after('fallback_variant');
            $table->string('preferred_subtitle_language', 16)->nullable()->after('preferred_playback_mode');
            $table->boolean('notify_preferred_translation')->default(false)->after('preferred_subtitle_language');
            $table->index(
                ['notify_preferred_translation', 'preferred_variant', 'user_id'],
                'user_account_preferred_translation_notify_idx',
            );
        });

        Schema::create('user_hidden_playback_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('variant_key', 160);
            $table->timestamps();

            $table->unique(['user_id', 'variant_key'], 'user_hidden_playback_variant_unique');
        });

        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->string('subtitle_language', 16)->nullable()->after('has_subtitles');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_hidden_playback_variants');

        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->dropColumn('subtitle_language');
        });

        Schema::table('user_account_settings', function (Blueprint $table): void {
            $table->dropIndex('user_account_preferred_translation_notify_idx');
            $table->dropColumn([
                'fallback_variant',
                'preferred_playback_mode',
                'preferred_subtitle_language',
                'notify_preferred_translation',
            ]);
        });
    }
};
