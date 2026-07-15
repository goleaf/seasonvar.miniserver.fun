<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Enums\SeasonvarSourceAvailability;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\SourcePage;
use Illuminate\Database\Eloquent\Builder;

final class SeasonvarTitlePageStateSynchronizer
{
    /**
     * @return list<string>
     */
    public function synchronize(CatalogTitle $catalogTitle, SourcePage $currentPage, ?int $importRunId): array
    {
        $catalogTitle = $catalogTitle->fresh([
            'seasons.episodes',
            'seasons.licensedMedia',
            'licensedMedia',
        ]) ?? $catalogTitle;

        $flags = $this->missingDataFlags($catalogTitle);
        $retryAfter = $flags === [] ? null : now()->addHours(
            max(1, (int) config('seasonvar.import.missing_data_retry_hours', 24)),
        );
        $providerRetryAfter = now()->addHours(
            max(1, (int) config('seasonvar.provider_availability.retry_hours', 168)),
        );

        $currentPage->update([
            'import_status' => $flags === [] ? 'parsed' : 'missing_data',
            'missing_data_flags' => $flags,
            'retry_after_at' => $currentPage->provider_availability_status === SeasonvarSourceAvailability::RegionBlocked
                ? $providerRetryAfter
                : $retryAfter,
            'failure_count' => 0,
            'last_imported_at' => now(),
            'last_import_run_id' => $importRunId,
        ]);

        $seasonUrlHashes = $catalogTitle->seasons
            ->pluck('source_url_hash')
            ->filter()
            ->unique()
            ->values();

        $linkedPageIds = SourcePage::query()
            ->where('source_id', $catalogTitle->source_id)
            ->where(function (Builder $query) use ($catalogTitle, $currentPage, $seasonUrlHashes): void {
                $query->whereKey($currentPage->id);

                if ($catalogTitle->source_page_id !== null) {
                    $query->orWhere('id', $catalogTitle->source_page_id);
                }

                if ($seasonUrlHashes->isNotEmpty()) {
                    $query->orWhereIn('url_hash', $seasonUrlHashes);
                }
            })
            ->pluck('id');

        SourcePage::query()
            ->whereKey($linkedPageIds)
            ->where('id', '!=', $currentPage->id)
            ->where('parse_status', 'parsed')
            ->whereIn('import_status', ['parsed', 'missing_data'])
            ->where(function (Builder $query): void {
                $query->whereNull('import_claim_token')
                    ->orWhereNull('import_claim_expires_at')
                    ->orWhere('import_claim_expires_at', '<=', now());
            })
            ->update([
                'import_status' => $flags === [] ? 'parsed' : 'missing_data',
                'missing_data_flags' => json_encode($flags, JSON_THROW_ON_ERROR),
                'retry_after_at' => $retryAfter,
            ]);

        SourcePage::query()
            ->whereKey($linkedPageIds)
            ->where('id', '!=', $currentPage->id)
            ->where('parse_status', 'parsed')
            ->whereIn('import_status', ['parsed', 'missing_data'])
            ->where(function (Builder $query): void {
                $query->whereNull('import_claim_token')
                    ->orWhereNull('import_claim_expires_at')
                    ->orWhere('import_claim_expires_at', '<=', now());
            })
            ->where('provider_availability_status', SeasonvarSourceAvailability::RegionBlocked->value)
            ->update([
                'retry_after_at' => $providerRetryAfter,
            ]);

        return $flags;
    }

    /**
     * @return list<string>
     */
    private function missingDataFlags(CatalogTitle $catalogTitle): array
    {
        $flags = [];
        $seasons = $catalogTitle->seasons;
        $episodes = $seasons->flatMap->episodes;
        $media = $catalogTitle->licensedMedia;
        $publishedMedia = $media->where('status', 'published');

        if (! $seasons->isNotEmpty()) {
            $flags[] = 'no_seasons';
        }

        if (! $episodes->isNotEmpty()) {
            $flags[] = 'no_episodes';
        }

        if ($seasons->contains(fn (Season $season): bool => $season->episodes->isEmpty())) {
            $flags[] = 'seasons_without_episodes';
        }

        if (! $media->isNotEmpty()) {
            $flags[] = 'no_video';
        }

        if ($media->isNotEmpty() && ! $publishedMedia->isNotEmpty()) {
            $flags[] = 'no_published_video';
        }

        if ($seasons->contains(fn (Season $season): bool => $season->licensedMedia->where('status', 'published')->isEmpty())) {
            $flags[] = 'seasons_without_video';
        }

        if ($episodes->isNotEmpty()) {
            $publishedEpisodeIds = $publishedMedia
                ->pluck('episode_id')
                ->filter()
                ->unique()
                ->values();

            if ($episodes->whereNotIn('id', $publishedEpisodeIds)->isNotEmpty()) {
                $flags[] = 'episodes_without_video';
            }
        }

        if ($media->contains(fn (LicensedMedia $media): bool => $media->status === 'unavailable'
            || in_array($media->health_status->value, ['unavailable', 'disabled'], true))) {
            $flags[] = 'unavailable_video';
        }

        return $flags;
    }
}
