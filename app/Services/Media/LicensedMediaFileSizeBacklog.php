<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\DTOs\LicensedMediaFileSizeBacklogStatusData;
use App\Enums\MediaFileSizeCheckStatus;
use App\Models\LicensedMedia;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheWindow;
use App\Support\Cache\TieredCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use UnexpectedValueException;

final class LicensedMediaFileSizeBacklog
{
    private const CACHE_RESOURCE = 'licensed-media-file-size-backlog-v4';

    private const EFFECTIVE_URL_SQL = "COALESCE(NULLIF(playback_url, ''), path)";

    public function __construct(
        private readonly TieredCache $cache,
    ) {}

    /** @return Builder<LicensedMedia> */
    public function query(bool $force = false): Builder
    {
        $query = $this->eligibleQuery();

        if (! $force) {
            $this->applyDueConstraint($query);
        }

        return $query;
    }

    public function status(): LicensedMediaFileSizeBacklogStatusData
    {
        $result = $this->cache->remember(
            CacheDomain::Operational,
            self::CACHE_RESOURCE,
            [
                'formats' => $this->directFormats(),
                'known_ttl' => $this->knownTtlSeconds(),
                'unknown_retry' => $this->unknownRetrySeconds(),
                'failed_retry' => $this->failedRetrySeconds(),
                'status_ttl' => $this->statusCacheSeconds(),
            ],
            $this->cacheWindow(),
            fn (): array => $this->buildStatus()->toArray(),
        );

        if (! is_array($result->value)) {
            throw new UnexpectedValueException('Cached file-size backlog status has an invalid shape.');
        }

        return LicensedMediaFileSizeBacklogStatusData::fromArray($result->value);
    }

    private function buildStatus(): LicensedMediaFileSizeBacklogStatusData
    {
        $knownCutoff = $this->knownCutoff();
        $unknownCutoff = $this->unknownCutoff();
        $failedCutoff = $this->failedCutoff();
        $known = MediaFileSizeCheckStatus::Known->value;
        $unknown = MediaFileSizeCheckStatus::Unknown->value;
        $unsupported = MediaFileSizeCheckStatus::Unsupported->value;
        $failed = MediaFileSizeCheckStatus::Failed->value;
        $pending = MediaFileSizeCheckStatus::Pending->value;

        $row = $this->eligibleQuery()
            ->selectRaw(<<<'SQL'
                COUNT(*) AS eligible,
                SUM(CASE WHEN file_size_check_status IN (?, ?, ?, ?) AND file_size_checked_at IS NOT NULL THEN 1 ELSE 0 END) AS checked,
                SUM(CASE WHEN file_size_check_status IS NULL OR file_size_check_status = ? OR file_size_checked_at IS NULL THEN 1 ELSE 0 END) AS pending,
                SUM(CASE
                    WHEN file_size_check_status IS NULL OR file_size_checked_at IS NULL OR file_size_check_status = ? THEN 1
                    WHEN file_size_check_status = ? AND file_size_checked_at <= ? THEN 1
                    WHEN file_size_check_status = ? AND file_size_checked_at <= ? THEN 1
                    WHEN file_size_check_status = ? AND file_size_checked_at <= ? THEN 1
                    ELSE 0
                END) AS due,
                SUM(CASE WHEN file_size_check_status = ? THEN 1 ELSE 0 END) AS known,
                SUM(CASE WHEN file_size_check_status = ? THEN 1 ELSE 0 END) AS unknown,
                SUM(CASE WHEN file_size_check_status = ? THEN 1 ELSE 0 END) AS unsupported,
                SUM(CASE WHEN file_size_check_status = ? THEN 1 ELSE 0 END) AS failed,
                COALESCE(SUM(CASE WHEN file_size_check_status = ? AND file_size_bytes IS NOT NULL THEN file_size_bytes ELSE 0 END), 0) AS known_bytes
                SQL, [
                $known,
                $unknown,
                $unsupported,
                $failed,
                $pending,
                $pending,
                $known,
                $knownCutoff,
                $unknown,
                $unknownCutoff,
                $failed,
                $failedCutoff,
                $known,
                $unknown,
                $unsupported,
                $failed,
                $known,
            ])
            ->toBase()
            ->first();

        if (! is_object($row)) {
            throw new UnexpectedValueException('File-size backlog aggregate did not return a row.');
        }

        return new LicensedMediaFileSizeBacklogStatusData(
            eligible: (int) $row->eligible,
            checked: (int) $row->checked,
            pending: (int) $row->pending,
            due: (int) $row->due,
            known: (int) $row->known,
            unknown: (int) $row->unknown,
            unsupported: (int) $row->unsupported,
            failed: (int) $row->failed,
            knownBytes: (int) $row->known_bytes,
            capturedAt: CarbonImmutable::now(),
        );
    }

    /** @return Builder<LicensedMedia> */
    private function eligibleQuery(): Builder
    {
        $formats = $this->directFormats();

        if ($formats === []) {
            return LicensedMedia::query()->whereRaw('1 = 0');
        }

        return LicensedMedia::query()
            ->whereIn('format', $formats)
            ->where(function (Builder $query): void {
                $query->whereRaw(self::EFFECTIVE_URL_SQL.' LIKE ?', ['http://%'])
                    ->orWhereRaw(self::EFFECTIVE_URL_SQL.' LIKE ?', ['https://%']);
            });
    }

    /** @param Builder<LicensedMedia> $query */
    private function applyDueConstraint(Builder $query): void
    {
        $knownCutoff = $this->knownCutoff();
        $unknownCutoff = $this->unknownCutoff();
        $failedCutoff = $this->failedCutoff();

        $query->where(function (Builder $query) use ($knownCutoff, $unknownCutoff, $failedCutoff): void {
            $query->whereNull('file_size_check_status')
                ->orWhereNull('file_size_checked_at')
                ->orWhere('file_size_check_status', MediaFileSizeCheckStatus::Pending->value)
                ->orWhere(function (Builder $query) use ($knownCutoff): void {
                    $query->where('file_size_check_status', MediaFileSizeCheckStatus::Known->value)
                        ->where('file_size_checked_at', '<=', $knownCutoff);
                })
                ->orWhere(function (Builder $query) use ($unknownCutoff): void {
                    $query->where('file_size_check_status', MediaFileSizeCheckStatus::Unknown->value)
                        ->where('file_size_checked_at', '<=', $unknownCutoff);
                })
                ->orWhere(function (Builder $query) use ($failedCutoff): void {
                    $query->where('file_size_check_status', MediaFileSizeCheckStatus::Failed->value)
                        ->where('file_size_checked_at', '<=', $failedCutoff);
                });
        });
    }

    /** @return list<string> */
    private function directFormats(): array
    {
        return collect((array) config('playback.downloads.allowed_formats', ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi']))
            ->map(fn (mixed $format): string => strtolower(trim((string) $format)))
            ->filter(fn (string $format): bool => preg_match('/\A[a-z0-9]{2,8}\z/', $format) === 1)
            ->unique()
            ->values()
            ->all();
    }

    private function knownCutoff(): Carbon
    {
        return now()->subSeconds($this->knownTtlSeconds());
    }

    private function unknownCutoff(): Carbon
    {
        return now()->subSeconds($this->unknownRetrySeconds());
    }

    private function failedCutoff(): Carbon
    {
        return now()->subSeconds($this->failedRetrySeconds());
    }

    private function knownTtlSeconds(): int
    {
        return max(0, (int) config('seasonvar.media_file_size.known_ttl_seconds', 2_592_000));
    }

    private function unknownRetrySeconds(): int
    {
        return max(0, (int) config('seasonvar.media_file_size.unknown_retry_seconds', 86_400));
    }

    private function failedRetrySeconds(): int
    {
        return max(0, (int) config('seasonvar.media_file_size.failed_retry_seconds', 21_600));
    }

    private function cacheWindow(): CacheWindow
    {
        $fresh = $this->statusCacheSeconds();

        return new CacheWindow(
            freshSeconds: $fresh,
            staleSeconds: max($fresh, min(86_400, $fresh * 12)),
            hotSeconds: min(60, $fresh),
            negativeSeconds: $fresh,
            lockSeconds: 30,
            waitMilliseconds: 5_000,
            jitterPercent: 10,
        );
    }

    private function statusCacheSeconds(): int
    {
        return max(30, min(
            3_600,
            (int) config('seasonvar.media_file_size.status_cache_seconds', 900),
        ));
    }
}
