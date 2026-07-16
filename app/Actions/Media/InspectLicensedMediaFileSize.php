<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\DTOs\ExternalMediaFileSizeResultData;
use App\Enums\MediaFileSizeCheckStatus;
use App\Enums\MediaFileSizeMetadataWriteStatus;
use App\Models\LicensedMedia;
use App\Services\Media\ExternalMediaFileSizeInspector;
use App\Services\Media\ExternalMediaFileType;
use App\Services\Media\LicensedMediaFileSizeMetadataWriter;
use App\Support\HumanFileSizeFormatter;
use Throwable;

final class InspectLicensedMediaFileSize
{
    public function __construct(
        private readonly ExternalMediaFileSizeInspector $inspector,
        private readonly ExternalMediaFileType $fileTypes,
        private readonly HumanFileSizeFormatter $fileSizes,
        private readonly LicensedMediaFileSizeMetadataWriter $metadata,
    ) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, mixed>  $context
     */
    public function execute(
        LicensedMedia $media,
        ?callable $progress = null,
        bool $force = false,
        array $context = [],
    ): bool {
        if (! (bool) config('seasonvar.media_file_size.enabled', true)) {
            $this->report($progress, 'seasonvar-media-size-check-skipped', $this->context($media, $context, [
                'reason' => 'проверка размера отключена',
            ]));

            return false;
        }

        if (! $this->shouldInspect($media, $force)) {
            $this->report($progress, 'seasonvar-media-size-check-skipped', $this->context($media, $context, [
                'reason' => 'сохранённые данные ещё актуальны',
                'check_status' => $media->file_size_check_status?->value,
            ]));

            return false;
        }

        $this->report($progress, 'seasonvar-media-size-check-started', $this->context($media, $context));
        $source = $this->metadata->snapshot($media);

        try {
            $result = $this->inspector->inspect($media);
            $writeStatus = $this->metadata->writeIfSourceMatches($media, $source, $result);

            if ($writeStatus === MediaFileSizeMetadataWriteStatus::SourceChanged) {
                $this->report($progress, 'seasonvar-media-size-check-skipped', $this->context($media, $context, [
                    'reason' => 'источник видео изменился во время проверки',
                ]));

                return false;
            }

            $this->reportResult($progress, $media, $result, $context);

            return $writeStatus === MediaFileSizeMetadataWriteStatus::Changed;
        } catch (Throwable $exception) {
            $this->report($progress, 'seasonvar-media-size-check-failed', $this->context($media, $context, [
                'check_status' => MediaFileSizeCheckStatus::Failed->value,
                'reason' => 'результат проверки размера не удалось сохранить',
                'exception' => $exception::class,
            ]));

            return false;
        }
    }

    public function shouldInspect(LicensedMedia $media, bool $force = false): bool
    {
        if ($force) {
            return true;
        }

        $status = $media->file_size_check_status;

        if ($status === null || $status === MediaFileSizeCheckStatus::Pending || $media->file_size_checked_at === null) {
            return true;
        }

        $retrySeconds = match ($status) {
            MediaFileSizeCheckStatus::Known => $this->configuredSeconds('known_ttl_seconds', 2_592_000),
            MediaFileSizeCheckStatus::Unknown => $this->configuredSeconds('unknown_retry_seconds', 86_400),
            MediaFileSizeCheckStatus::Failed => $this->configuredSeconds('failed_retry_seconds', 21_600),
            MediaFileSizeCheckStatus::Unsupported => PHP_INT_MAX,
        };

        return $retrySeconds !== PHP_INT_MAX
            && $media->file_size_checked_at->addSeconds($retrySeconds)->isPast();
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, mixed>  $context
     */
    private function reportResult(
        ?callable $progress,
        LicensedMedia $media,
        ExternalMediaFileSizeResultData $result,
        array $context,
    ): void {
        $event = match ($result->status) {
            MediaFileSizeCheckStatus::Known => 'seasonvar-media-size-known',
            MediaFileSizeCheckStatus::Unknown => 'seasonvar-media-size-unknown',
            MediaFileSizeCheckStatus::Unsupported => 'seasonvar-media-size-unsupported',
            MediaFileSizeCheckStatus::Failed => 'seasonvar-media-size-check-failed',
            MediaFileSizeCheckStatus::Pending => 'seasonvar-media-size-check-skipped',
        };

        $this->report($progress, $event, $this->context($media, $context, [
            'file_size_bytes' => $result->bytes,
            'file_size_human' => $this->fileSizes->format($result->bytes, 'ru'),
            'file_size_source' => $result->source,
            'http_status' => $result->httpStatus,
            'check_status' => $result->status->value,
            'reason' => $result->errorCategory,
        ]));
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function context(LicensedMedia $media, array $context, array $extra = []): array
    {
        return [
            'licensed_media_id' => $media->id,
            'catalog_title_id' => $media->catalog_title_id,
            'catalog_title' => $context['catalog_title'] ?? null,
            'season_number' => $context['season_number'] ?? null,
            'episode_number' => $context['episode_number'] ?? null,
            'format' => $this->fileTypes->format($media),
            ...$extra,
        ];
    }

    private function configuredSeconds(string $key, int $default): int
    {
        return max(0, (int) config('seasonvar.media_file_size.'.$key, $default));
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, mixed>  $context
     */
    private function report(?callable $progress, string $event, array $context): void
    {
        if ($progress !== null) {
            $progress($event, array_filter($context, fn (mixed $value): bool => $value !== null));
        }
    }
}
