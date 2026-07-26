<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playback_quality_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('catalog_title_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('season_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('episode_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('initial_media_id')->nullable()->constrained('licensed_media')->nullOnDelete();
            $table->foreignId('current_media_id')->nullable()->constrained('licensed_media')->nullOnDelete();
            $table->string('provider_code', 64)->nullable();
            $table->string('error_provider_code', 64)->nullable();
            $table->string('variant_code', 64)->nullable();
            $table->string('quality_code', 24)->nullable();
            $table->string('translation_name', 120)->nullable();
            $table->string('format_code', 16)->nullable();
            $table->string('browser_family', 24)->nullable();
            $table->unsignedSmallInteger('browser_major')->nullable();
            $table->string('operating_system', 24)->nullable();
            $table->string('hls_support', 16)->nullable();
            $table->string('error_type', 48)->nullable();
            $table->string('error_source', 16)->nullable();
            $table->unsignedInteger('startup_time_ms')->nullable();
            $table->unsignedBigInteger('playback_time_ms')->default(0);
            $table->unsignedBigInteger('buffering_time_ms')->default(0);
            $table->unsignedSmallInteger('buffering_count')->default(0);
            $table->unsignedInteger('playback_position_seconds')->default(0);
            $table->boolean('reached_playback')->default(false);
            $table->boolean('playback_failed')->default(false);
            $table->boolean('fallback_attempted')->default(false);
            $table->boolean('fallback_succeeded')->default(false);
            $table->boolean('primary_failed')->default(false);
            $table->boolean('fallback_failed')->default(false);
            $table->string('network_test_status', 16)->nullable();
            $table->unsignedInteger('network_latency_ms')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('last_event_at');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->index(['started_at', 'id'], 'playback_quality_retention_idx');
            $table->index(['playback_failed', 'started_at', 'browser_family', 'browser_major'], 'playback_quality_browser_errors_idx');
            $table->index(['playback_failed', 'started_at', 'error_provider_code'], 'playback_quality_provider_errors_idx');
            $table->index(['playback_failed', 'started_at', 'quality_code'], 'playback_quality_quality_errors_idx');
        });

        Schema::table('technical_issue_diagnostics', function (Blueprint $table): void {
            $table->uuid('playback_request_id')->nullable();
            $table->string('playback_error_type', 48)->nullable();
            $table->string('playback_error_source', 16)->nullable();
            $table->unsignedInteger('startup_time_ms')->nullable();
            $table->unsignedBigInteger('playback_time_ms')->nullable();
            $table->unsignedBigInteger('buffering_time_ms')->nullable();
            $table->unsignedSmallInteger('buffering_count')->nullable();
            $table->boolean('fallback_attempted')->nullable();
            $table->boolean('fallback_succeeded')->nullable();
            $table->boolean('primary_failed')->nullable();
            $table->boolean('fallback_failed')->nullable();
            $table->string('network_test_status', 16)->nullable();
            $table->unsignedInteger('network_latency_ms')->nullable();
            $table->string('video_variant_code', 64)->nullable();
            $table->string('video_quality_code', 24)->nullable();
            $table->string('video_translation_name', 120)->nullable();
            $table->string('video_format_code', 16)->nullable();
            $table->string('video_provider_code', 64)->nullable();
            $table->string('hls_support', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('technical_issue_diagnostics', function (Blueprint $table): void {
            $table->dropColumn([
                'playback_request_id',
                'playback_error_type',
                'playback_error_source',
                'startup_time_ms',
                'playback_time_ms',
                'buffering_time_ms',
                'buffering_count',
                'fallback_attempted',
                'fallback_succeeded',
                'primary_failed',
                'fallback_failed',
                'network_test_status',
                'network_latency_ms',
                'video_variant_code',
                'video_quality_code',
                'video_translation_name',
                'video_format_code',
                'video_provider_code',
                'hls_support',
            ]);
        });

        Schema::dropIfExists('playback_quality_sessions');
    }
};
