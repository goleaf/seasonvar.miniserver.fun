<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use App\DTOs\ContentRequests\ContentExistenceResult;
use App\DTOs\ContentRequests\ContentRequestInput;
use App\Enums\ContentRequestExternalProvider;
use App\Enums\ContentRequestType;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\Catalog\Search\CatalogSearchNormalizer;
use Illuminate\Database\Eloquent\Builder;

final readonly class ContentExistenceService
{
    public function __construct(private CatalogSearchNormalizer $normalizer) {}

    public function check(ContentRequestInput $input): ContentExistenceResult
    {
        return match ($input->type) {
            ContentRequestType::Serial => $this->serial($input),
            ContentRequestType::Season => $this->season($input),
            ContentRequestType::Episode => $this->episode($input),
            ContentRequestType::Subtitles => $this->media($input, subtitles: true),
            ContentRequestType::QualityUpgrade => $this->media($input, quality: $input->requestedQuality),
            default => new ContentExistenceResult(false, $this->targetMatch($input)),
        };
    }

    private function serial(ContentRequestInput $input): ContentExistenceResult
    {
        $search = str_replace(['%', '_', '\\'], '', $input->originalTitle ?: $input->title);
        $normalized = $this->normalizer->key($input->originalTitle ?: $input->title);
        $seasonvarId = collect($input->externalIdentifiers)
            ->firstWhere('provider', ContentRequestExternalProvider::Seasonvar->value)['identifier'] ?? null;
        $candidates = CatalogTitle::query()
            ->availableTo(null)
            ->with('aliases:id,catalog_title_id,name')
            ->select(['id', 'slug', 'title', 'original_title', 'year', 'external_id'])
            ->where(function (Builder $query) use ($search, $seasonvarId): void {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('original_title', 'like', "%{$search}%")
                    ->orWhereHas('aliases', fn (Builder $aliases): Builder => $aliases->where('name', 'like', "%{$search}%"))
                    ->when($seasonvarId !== null, fn (Builder $query): Builder => $query->orWhere('external_id', $seasonvarId));
            })
            ->when($input->releaseYear !== null, fn (Builder $query): Builder => $query->whereBetween('year', [$input->releaseYear - 1, $input->releaseYear + 1]))
            ->orderBy('year')->orderBy('id')->limit(20)->get();
        $matches = $candidates->map(fn (CatalogTitle $title): array => [
            'kind' => 'serial',
            'label' => $title->display_title.($title->year !== null ? ' ('.$title->year.')' : ''),
            'url' => route('titles.show', $title),
            'exact' => ($seasonvarId !== null && (string) $title->external_id === $seasonvarId)
                || (collect([$title->title, $title->original_title])
                    ->concat($title->aliases->pluck('name'))
                    ->contains(fn (?string $candidate): bool => $this->normalizer->key((string) $candidate) === $normalized)
                    && ($input->releaseYear === null || $title->year === $input->releaseYear)),
        ])->all();

        return new ContentExistenceResult(collect($matches)->contains('exact', true), collect($matches)->map(fn (array $match): array => array_diff_key($match, ['exact' => true]))->all());
    }

    private function season(ContentRequestInput $input): ContentExistenceResult
    {
        $season = Season::query()
            ->availableTo(null)
            ->where('catalog_title_id', $input->catalogTitleId)
            ->whereHas('catalogTitle', fn (Builder $title): Builder => $title->availableTo(null))
            ->where('number', $input->seasonNumber)
            ->when($input->seasonKind !== null, fn (Builder $query): Builder => $query->where('kind', $input->seasonKind))
            ->first();

        return new ContentExistenceResult($season !== null, $season === null ? $this->targetMatch($input) : [$this->seasonMatch($season)]);
    }

    private function episode(ContentRequestInput $input): ContentExistenceResult
    {
        $episode = Episode::query()
            ->availableTo(null)
            ->where('season_id', $input->seasonId)
            ->whereHas('season', fn (Builder $season): Builder => $season->availableTo(null)
                ->whereHas('catalogTitle', fn (Builder $title): Builder => $title->availableTo(null)))
            ->where('number', $input->episodeNumber)
            ->first();

        return new ContentExistenceResult($episode !== null, $episode === null ? $this->targetMatch($input) : [$this->episodeMatch($episode)]);
    }

    private function media(ContentRequestInput $input, bool $subtitles = false, ?string $quality = null): ContentExistenceResult
    {
        $query = LicensedMedia::query()->published();
        $this->applyTarget($query, $input);

        if ($subtitles) {
            $query->where('has_subtitles', true);
        }

        if ($quality !== null) {
            $query->where('quality', $quality);
        }

        $exists = $query->exists();

        // The current media schema stores only a subtitles flag, not a language code.
        // Show availability to the requester, but do not block a language-specific request.
        return new ContentExistenceResult($subtitles ? false : $exists, $this->targetMatch($input));
    }

    /** @return list<array{kind: string, label: string, url: string}> */
    private function targetMatch(ContentRequestInput $input): array
    {
        if ($input->catalogTitleId === null) {
            return [];
        }

        $title = CatalogTitle::query()->availableTo(null)->find($input->catalogTitleId, ['id', 'slug', 'title', 'original_title']);

        return $title === null ? [] : [['kind' => 'serial', 'label' => $title->display_title, 'url' => route('titles.show', $title)]];
    }

    /** @return array{kind: string, label: string, url: string} */
    private function seasonMatch(Season $season): array
    {
        $title = CatalogTitle::query()->findOrFail($season->catalog_title_id, ['id', 'slug', 'title', 'original_title']);

        return ['kind' => 'season', 'label' => $title->display_title.' · '.__('requests.fields.season_number_value', ['number' => $season->number]), 'url' => route('titles.show', [$title, 'season' => $season->number])];
    }

    /** @return array{kind: string, label: string, url: string} */
    private function episodeMatch(Episode $episode): array
    {
        $episode->loadMissing([
            'season:id,catalog_title_id,number',
            'season.catalogTitle:id,slug,title,original_title',
        ]);
        $title = $episode->season->catalogTitle;

        return ['kind' => 'episode', 'label' => $title->display_title.' · '.__('requests.fields.episode_number_value', ['number' => $episode->number]), 'url' => route('titles.show', [$title, 'season' => $episode->season->number, 'episode' => $episode->number])];
    }

    /** @param Builder<LicensedMedia> $query */
    private function applyTarget(Builder $query, ContentRequestInput $input): void
    {
        if ($input->episodeId !== null) {
            $query->where('episode_id', $input->episodeId);
        } elseif ($input->seasonId !== null) {
            $query->where('season_id', $input->seasonId);
        } else {
            $query->where('catalog_title_id', $input->catalogTitleId);
        }
    }
}
