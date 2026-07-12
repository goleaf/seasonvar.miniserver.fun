<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episode_view_progress', function (Blueprint $table): void {
            $table->foreignId('licensed_media_id')
                ->nullable()
                ->after('episode_id')
                ->constrained('licensed_media')
                ->nullOnDelete();
            $table->unsignedTinyInteger('progress_percent')->nullable()->after('duration_seconds');
            $table->timestamp('first_started_at')->nullable()->after('progress_percent');
            $table->string('playback_session_id', 26)->nullable()->after('first_started_at');
            $table->unsignedBigInteger('playback_event_sequence')->default(0)->after('playback_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('episode_view_progress', function (Blueprint $table): void {
            $table->dropForeign(['licensed_media_id']);
            $table->dropColumn([
                'licensed_media_id',
                'progress_percent',
                'first_started_at',
                'playback_session_id',
                'playback_event_sequence',
            ]);
        });
    }
};
