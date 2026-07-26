<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\PlaybackQualitySession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlaybackQualityRecorder
{
    public function __construct(
        private readonly PlaybackQualityContext $context,
        private readonly PlaybackQualitySchema $schema,
    ) {}

    /** @param array<string, mixed> $sample */
    public function record(array $sample, ?User $user): PlaybackQualitySession
    {
        if (! $this->schema->ready()) {
            throw ValidationException::withMessages(['context' => 'Диагностика воспроизведения временно недоступна.']);
        }

        $titleId = $this->context->captureTitleId((string) $sample['context']);
        $title = $titleId !== null
            ? CatalogTitle::query()->availableTo($user)->find($titleId, ['id'])
            : null;

        if (! $title instanceof CatalogTitle) {
            throw ValidationException::withMessages(['context' => 'Контекст воспроизведения устарел или недоступен.']);
        }

        $media = LicensedMedia::query()
            ->availableTo($user)
            ->forAvailableReleases($user)
            ->withPlaybackLocation()
            ->withoutKnownFailures()
            ->where('catalog_title_id', $title->id)
            ->find($sample['media_id'], [
                'id',
                'catalog_title_id',
                'season_id',
                'episode_id',
                'storage_disk',
                'quality',
                'translation_name',
                'variant_type',
                'variant_key',
                'variant_name',
                'format',
            ]);

        if (! $media instanceof LicensedMedia) {
            throw ValidationException::withMessages(['media_id' => 'Вариант видео не принадлежит доступному сериалу.']);
        }

        return DB::transaction(function () use ($sample, $media): PlaybackQualitySession {
            $now = now();
            PlaybackQualitySession::query()->firstOrCreate(
                ['request_id' => $sample['request_id']],
                [
                    'catalog_title_id' => $media->catalog_title_id,
                    'season_id' => $media->season_id,
                    'episode_id' => $media->episode_id,
                    'initial_media_id' => $media->id,
                    ...$this->mediaSnapshot($media),
                    'browser_family' => $sample['browser_family'] ?? null,
                    'browser_major' => $sample['browser_major'] ?? null,
                    'operating_system' => $sample['operating_system'] ?? null,
                    'hls_support' => $sample['hls_support'] ?? null,
                    'started_at' => $now,
                    'last_event_at' => $now,
                ],
            );

            $session = PlaybackQualitySession::query()
                ->where('request_id', $sample['request_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $session->catalog_title_id !== (int) $media->catalog_title_id
                || (int) $session->episode_id !== (int) $media->episode_id) {
                throw ValidationException::withMessages(['request_id' => 'Идентификатор уже относится к другому воспроизведению.']);
            }

            $event = (string) $sample['event'];
            $isFallback = (int) $session->initial_media_id !== (int) $media->id;
            $isError = $event === 'error' || ($event === 'report' && isset($sample['error_type']));
            $isReady = $event === 'ready';
            $isFinal = in_array($event, ['ended', 'report'], true);
            $errorSource = $isFallback ? 'fallback' : 'primary';

            $session->forceFill([
                'current_media_id' => $media->id,
                ...$this->mediaSnapshot($media),
                'browser_family' => $sample['browser_family'] ?? $session->browser_family,
                'browser_major' => $sample['browser_major'] ?? $session->browser_major,
                'operating_system' => $sample['operating_system'] ?? $session->operating_system,
                'hls_support' => $sample['hls_support'] ?? $session->hls_support,
                'startup_time_ms' => $this->minimumNullable($session->startup_time_ms, $sample['startup_time_ms'] ?? null),
                'playback_time_ms' => max((int) $session->playback_time_ms, (int) $sample['playback_time_ms']),
                'buffering_time_ms' => max((int) $session->buffering_time_ms, (int) $sample['buffering_time_ms']),
                'buffering_count' => max((int) $session->buffering_count, (int) $sample['buffering_count']),
                'playback_position_seconds' => max((int) $session->playback_position_seconds, (int) $sample['playback_position_seconds']),
                'reached_playback' => $session->reached_playback || $isReady,
                'fallback_attempted' => $session->fallback_attempted || $isFallback || $event === 'fallback',
                'fallback_succeeded' => $session->fallback_succeeded || ($isFallback && $isReady),
                'primary_failed' => $session->primary_failed || ($isError && ! $isFallback),
                'fallback_failed' => $session->fallback_failed || ($isError && $isFallback),
                'playback_failed' => $isError ? true : ($isReady ? false : $session->playback_failed),
                'error_type' => $isError ? ($sample['error_type'] ?? 'unknown') : $session->error_type,
                'error_source' => $isError ? $errorSource : $session->error_source,
                'error_provider_code' => $isError ? $this->providerCode($media) : $session->error_provider_code,
                'network_test_status' => $event === 'report' ? ($sample['network_test_status'] ?? null) : $session->network_test_status,
                'network_latency_ms' => $event === 'report' ? ($sample['network_latency_ms'] ?? null) : $session->network_latency_ms,
                'last_event_at' => $now,
                'finalized_at' => $isFinal ? $now : $session->finalized_at,
            ])->save();

            return $session->refresh();
        }, attempts: 3);
    }

    /** @return array<string, string|null> */
    private function mediaSnapshot(LicensedMedia $media): array
    {
        return [
            'provider_code' => $this->providerCode($media),
            'variant_code' => $this->bounded($media->variant_key ?: $media->variant_name ?: $media->variant_type, 64),
            'quality_code' => $this->bounded($media->quality, 24),
            'translation_name' => $this->bounded($media->translation_name, 120),
            'format_code' => $this->bounded($media->format, 16),
        ];
    }

    private function providerCode(LicensedMedia $media): ?string
    {
        return $this->bounded($media->storage_disk, 64);
    }

    private function bounded(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, $max) : null;
    }

    private function minimumNullable(?int $current, mixed $candidate): ?int
    {
        if (! is_int($candidate)) {
            return $current;
        }

        return $current === null ? $candidate : min($current, $candidate);
    }
}
