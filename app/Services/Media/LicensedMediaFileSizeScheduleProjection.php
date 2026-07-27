<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\DTOs\LicensedMediaFileSizeBacklogStatusData;
use App\Enums\MediaFileSizeCheckStatus;
use App\Models\LicensedMedia;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

final class LicensedMediaFileSizeScheduleProjection
{
    private const STATE_ID = 1;

    public function isEnabled(): bool
    {
        return (bool) config('seasonvar.media_file_size.projection_enabled', false);
    }

    public function isReady(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        return DB::table('licensed_media_file_size_state')
            ->where('id', self::STATE_ID)
            ->whereNotNull('projection_completed_at')
            ->exists();
    }

    /**
     * @return array{file_size_eligible: bool, file_size_next_check_at: CarbonImmutable|null}
     */
    public function attributesFor(LicensedMedia $media): array
    {
        return $this->attributesForValues(
            playbackUrl: $media->playback_url,
            path: $media->path,
            format: $media->format,
            status: $media->file_size_check_status,
            checkedAt: $media->file_size_checked_at,
        );
    }

    /**
     * @return array{file_size_eligible: bool, file_size_next_check_at: CarbonImmutable|null}
     */
    public function attributesForValues(
        ?string $playbackUrl,
        ?string $path,
        ?string $format,
        MediaFileSizeCheckStatus|string|null $status,
        ?CarbonInterface $checkedAt,
    ): array {
        $eligible = $this->isEligible($playbackUrl, $path, $format);

        if (! $eligible) {
            return [
                'file_size_eligible' => false,
                'file_size_next_check_at' => null,
            ];
        }

        $normalizedStatus = is_string($status)
            ? MediaFileSizeCheckStatus::tryFrom($status)
            : $status;

        if ($normalizedStatus === null
            || $normalizedStatus === MediaFileSizeCheckStatus::Pending
            || $checkedAt === null) {
            return [
                'file_size_eligible' => true,
                'file_size_next_check_at' => CarbonImmutable::now(),
            ];
        }

        $retrySeconds = match ($normalizedStatus) {
            MediaFileSizeCheckStatus::Known => $this->configuredSeconds('known_ttl_seconds', 2_592_000),
            MediaFileSizeCheckStatus::Unknown => $this->configuredSeconds('unknown_retry_seconds', 86_400),
            MediaFileSizeCheckStatus::Failed => $this->configuredSeconds('failed_retry_seconds', 21_600),
            MediaFileSizeCheckStatus::Unsupported => null,
        };

        return [
            'file_size_eligible' => true,
            'file_size_next_check_at' => $retrySeconds === null
                ? null
                : CarbonImmutable::instance($checkedAt)->addSeconds($retrySeconds),
        ];
    }

    public function applyTo(LicensedMedia $media): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $media->forceFill($this->attributesFor($media));
    }

    public function reconcileChunk(int $limit): int
    {
        if (! $this->isEnabled() || $this->isReady()) {
            return 0;
        }

        $limit = max(1, min(5_000, $limit));

        return DB::transaction(function () use ($limit): int {
            $state = DB::table('licensed_media_file_size_state')
                ->where('id', self::STATE_ID)
                ->lockForUpdate()
                ->first(['projection_cursor_id', 'projection_completed_at']);

            if (! is_object($state)) {
                throw new UnexpectedValueException('File-size projection state is missing.');
            }

            if ($state->projection_completed_at !== null) {
                return 0;
            }

            /** @var Collection<int, LicensedMedia> $media */
            $media = LicensedMedia::query()
                ->whereKeyNot(0)
                ->where('id', '>', (int) $state->projection_cursor_id)
                ->orderBy('id')
                ->limit($limit)
                ->get([
                    'id',
                    'path',
                    'playback_url',
                    'format',
                    'file_size_checked_at',
                    'file_size_check_status',
                ]);

            if ($media->isEmpty()) {
                DB::table('licensed_media_file_size_state')
                    ->where('id', self::STATE_ID)
                    ->update([
                        'projection_completed_at' => now(),
                        'updated_at' => now(),
                    ]);

                return 0;
            }

            $this->bulkUpdate($media);
            $lastId = (int) $media->last()->getKey();
            $complete = ! LicensedMedia::query()->where('id', '>', $lastId)->exists();

            DB::table('licensed_media_file_size_state')
                ->where('id', self::STATE_ID)
                ->update([
                    'projection_cursor_id' => $lastId,
                    'projection_completed_at' => $complete ? now() : null,
                    'updated_at' => now(),
                ]);

            return $media->count();
        }, 3);
    }

    public function storedStatus(): ?LicensedMediaFileSizeBacklogStatusData
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $state = DB::table('licensed_media_file_size_state')
            ->where('id', self::STATE_ID)
            ->first([
                'projection_completed_at',
                'eligible',
                'checked',
                'pending',
                'due',
                'known',
                'unknown',
                'unsupported',
                'failed',
                'known_bytes',
                'snapshot_captured_at',
            ]);

        if (! is_object($state)
            || $state->projection_completed_at === null
            || $state->snapshot_captured_at === null) {
            return null;
        }

        return $this->statusFromState($state);
    }

    public function captureStatus(): ?LicensedMediaFileSizeBacklogStatusData
    {
        if (! $this->isReady()) {
            return null;
        }

        $known = MediaFileSizeCheckStatus::Known->value;
        $unknown = MediaFileSizeCheckStatus::Unknown->value;
        $unsupported = MediaFileSizeCheckStatus::Unsupported->value;
        $failed = MediaFileSizeCheckStatus::Failed->value;
        $pending = MediaFileSizeCheckStatus::Pending->value;

        $row = LicensedMedia::query()
            ->where('file_size_eligible', true)
            ->selectRaw(<<<'SQL'
                COUNT(*) AS eligible,
                SUM(CASE WHEN file_size_check_status IN (?, ?, ?, ?) AND file_size_checked_at IS NOT NULL THEN 1 ELSE 0 END) AS checked,
                SUM(CASE WHEN file_size_check_status IS NULL OR file_size_check_status = ? OR file_size_checked_at IS NULL THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN file_size_next_check_at IS NOT NULL AND file_size_next_check_at <= ? THEN 1 ELSE 0 END) AS due,
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
                now(),
                $known,
                $unknown,
                $unsupported,
                $failed,
                $known,
            ])
            ->toBase()
            ->first();

        if (! is_object($row)) {
            throw new UnexpectedValueException('File-size projection aggregate did not return a row.');
        }

        $capturedAt = CarbonImmutable::now();
        $attributes = [
            'eligible' => (int) $row->eligible,
            'checked' => (int) $row->checked,
            'pending' => (int) $row->pending,
            'due' => (int) $row->due,
            'known' => (int) $row->known,
            'unknown' => (int) $row->unknown,
            'unsupported' => (int) $row->unsupported,
            'failed' => (int) $row->failed,
            'known_bytes' => (int) $row->known_bytes,
            'snapshot_captured_at' => $capturedAt,
            'updated_at' => now(),
        ];

        DB::table('licensed_media_file_size_state')
            ->where('id', self::STATE_ID)
            ->update($attributes);

        return new LicensedMediaFileSizeBacklogStatusData(
            eligible: $attributes['eligible'],
            checked: $attributes['checked'],
            pending: $attributes['pending'],
            due: $attributes['due'],
            known: $attributes['known'],
            unknown: $attributes['unknown'],
            unsupported: $attributes['unsupported'],
            failed: $attributes['failed'],
            knownBytes: $attributes['known_bytes'],
            capturedAt: $capturedAt,
        );
    }

    /**
     * @param  Collection<int, LicensedMedia>  $media
     */
    private function bulkUpdate(Collection $media): void
    {
        $eligibleCases = [];
        $nextCheckCases = [];
        $ids = [];
        $eligibleBindings = [];
        $nextCheckBindings = [];
        $idBindings = [];

        foreach ($media as $item) {
            $attributes = $this->attributesFor($item);
            $id = (int) $item->getKey();
            $eligibleCases[] = 'WHEN ? THEN ?';
            $nextCheckCases[] = 'WHEN ? THEN ?';
            $ids[] = '?';
            $eligibleBindings[] = $id;
            $eligibleBindings[] = $attributes['file_size_eligible'] ? 1 : 0;
            $nextCheckBindings[] = $id;
            $nextCheckBindings[] = $attributes['file_size_next_check_at']?->format('Y-m-d H:i:s');
            $idBindings[] = $id;
        }

        DB::update(sprintf(
            'UPDATE licensed_media SET file_size_eligible = CASE id %s END, file_size_next_check_at = CASE id %s END WHERE id IN (%s)',
            implode(' ', $eligibleCases),
            implode(' ', $nextCheckCases),
            implode(', ', $ids),
        ), [...$eligibleBindings, ...$nextCheckBindings, ...$idBindings]);
    }

    private function isEligible(?string $playbackUrl, ?string $path, ?string $format): bool
    {
        $normalizedFormat = strtolower(trim((string) $format));

        if (! in_array($normalizedFormat, $this->directFormats(), true)) {
            return false;
        }

        $url = trim((string) ($playbackUrl ?: $path));
        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && trim((string) ($parts['host'] ?? '')) !== '';
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

    private function configuredSeconds(string $key, int $default): int
    {
        return max(0, (int) config('seasonvar.media_file_size.'.$key, $default));
    }

    private function statusFromState(object $state): LicensedMediaFileSizeBacklogStatusData
    {
        return new LicensedMediaFileSizeBacklogStatusData(
            eligible: (int) $state->eligible,
            checked: (int) $state->checked,
            pending: (int) $state->pending,
            due: (int) $state->due,
            known: (int) $state->known,
            unknown: (int) $state->unknown,
            unsupported: (int) $state->unsupported,
            failed: (int) $state->failed,
            knownBytes: (int) $state->known_bytes,
            capturedAt: CarbonImmutable::parse((string) $state->snapshot_captured_at),
        );
    }
}
