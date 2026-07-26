<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\TechnicalIssues\TechnicalIssueTargetData;
use App\Models\PlaybackQualitySession;

final class PlaybackQualityReportSnapshot
{
    public function __construct(
        private readonly PlaybackQualityContext $context,
        private readonly PlaybackQualitySchema $schema,
    ) {}

    public function resolve(string $token, TechnicalIssueTargetData $target): ?PlaybackQualitySession
    {
        if (! $this->schema->ready()) {
            return null;
        }

        $payload = $this->context->reportPayload($token);

        if ($payload === null
            || $target->catalogTitleId !== $payload['title_id']
            || $target->licensedMediaId !== $payload['media_id']) {
            return null;
        }

        return PlaybackQualitySession::query()
            ->where('request_id', $payload['request_id'])
            ->where('catalog_title_id', $target->catalogTitleId)
            ->where('current_media_id', $target->licensedMediaId)
            ->when($target->seasonId !== null, fn ($query) => $query->where('season_id', $target->seasonId))
            ->when($target->episodeId !== null, fn ($query) => $query->where('episode_id', $target->episodeId))
            ->where('last_event_at', '>=', now()->subMinutes(max(5, (int) config('playback.quality.context_ttl_minutes', 120))))
            ->first();
    }

    /** @return array<string, mixed> */
    public function values(?PlaybackQualitySession $session): array
    {
        if (! $session instanceof PlaybackQualitySession) {
            return [];
        }

        return [
            'playback_request_id' => $session->request_id,
            'browser_family' => $session->browser_family,
            'browser_major' => $session->browser_major,
            'operating_system' => $session->operating_system,
            'hls_support' => $session->hls_support,
            'playback_error_type' => $session->error_type,
            'playback_error_source' => $session->error_source,
            'startup_time_ms' => $session->startup_time_ms,
            'playback_time_ms' => $session->playback_time_ms,
            'buffering_time_ms' => $session->buffering_time_ms,
            'buffering_count' => $session->buffering_count,
            'fallback_attempted' => $session->fallback_attempted,
            'fallback_succeeded' => $session->fallback_succeeded,
            'primary_failed' => $session->primary_failed,
            'fallback_failed' => $session->fallback_failed,
            'network_test_status' => $session->network_test_status,
            'network_latency_ms' => $session->network_latency_ms,
            'video_variant_code' => $session->variant_code,
            'video_quality_code' => $session->quality_code,
            'video_translation_name' => $session->translation_name,
            'video_format_code' => $session->format_code,
            'video_provider_code' => $session->provider_code,
        ];
    }
}
