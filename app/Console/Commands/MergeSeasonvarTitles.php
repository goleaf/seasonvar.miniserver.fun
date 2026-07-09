<?php

namespace App\Console\Commands;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('seasonvar:merge-titles {--dry-run : Только показать, что будет объединено}')]
#[Description('Объединяет сезонные страницы одного сериала в одну карточку каталога')]
class MergeSeasonvarTitles extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $groups = $this->duplicateTitleGroups();

        if ($groups->isEmpty()) {
            $this->info('Дубли сезонов не найдены.');

            return self::SUCCESS;
        }

        $mergedTitles = 0;
        $mergedSeasons = 0;
        $movedEpisodes = 0;

        foreach ($groups as $group) {
            $titles = CatalogTitle::query()
                ->with(['taxonomies', 'seasons.episodes'])
                ->whereKey($group->pluck('id'))
                ->orderBy('id')
                ->get();

            if ($titles->count() < 2) {
                continue;
            }

            $result = $dryRun
                ? $this->describeGroup($titles)
                : DB::transaction(fn (): array => $this->mergeGroup($titles));

            $mergedTitles += $result['titles'];
            $mergedSeasons += $result['seasons'];
            $movedEpisodes += $result['episodes'];

            $canonical = $titles->first();
            $this->line(sprintf(
                '%s: %d карточек, %d сезонов, %d серий -> %s',
                $canonical?->title,
                $result['titles'],
                $result['seasons'],
                $result['episodes'],
                $canonical?->slug,
            ));
        }

        $message = $dryRun ? 'Проверка завершена' : 'Слияние завершено';
        $this->info("{$message}: карточек {$mergedTitles}, сезонов {$mergedSeasons}, серий {$movedEpisodes}.");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Collection<int, CatalogTitle>>
     */
    private function duplicateTitleGroups(): Collection
    {
        return CatalogTitle::query()
            ->where('type', 'serial')
            ->orderBy('source_id')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (CatalogTitle $title): string => implode('|', [
                $title->source_id,
                $title->type,
                $this->normalizedSeriesTitleKey($title->title),
            ]))
            ->filter(fn (Collection $titles): bool => $titles->count() > 1)
            ->sortByDesc(fn (Collection $titles): int => $titles->count())
            ->values();
    }

    /**
     * @param  EloquentCollection<int, CatalogTitle>  $titles
     * @return array{titles: int, seasons: int, episodes: int}
     */
    private function describeGroup(EloquentCollection $titles): array
    {
        return [
            'titles' => $titles->count() - 1,
            'seasons' => $titles->slice(1)->sum(fn (CatalogTitle $title): int => $title->seasons->count()),
            'episodes' => $titles->slice(1)->sum(
                fn (CatalogTitle $title): int => $title->seasons->sum(fn (Season $season): int => $season->episodes->count()),
            ),
        ];
    }

    /**
     * @param  EloquentCollection<int, CatalogTitle>  $titles
     * @return array{titles: int, seasons: int, episodes: int}
     */
    private function mergeGroup(EloquentCollection $titles): array
    {
        $canonical = $titles->firstOrFail();
        $mergedTitles = 0;
        $mergedSeasons = 0;
        $movedEpisodes = 0;
        $taxonomyIds = $canonical->taxonomies->pluck('id')->all();

        foreach ($titles->slice(1) as $duplicate) {
            $taxonomyIds = array_values(array_unique([
                ...$taxonomyIds,
                ...$duplicate->taxonomies->pluck('id')->all(),
            ]));

            foreach ($duplicate->seasons as $season) {
                $targetSeason = Season::query()->firstOrCreate(
                    [
                        'catalog_title_id' => $canonical->id,
                        'number' => $season->number,
                    ],
                    [
                        'source_page_id' => $season->source_page_id,
                        'title' => $season->title,
                        'source_url' => $season->source_url,
                        'source_url_hash' => $season->source_url_hash,
                    ],
                );

                if ($season->episodes->isNotEmpty() || $targetSeason->source_url === null) {
                    $targetSeason->fill([
                        'source_page_id' => $season->source_page_id ?? $targetSeason->source_page_id,
                        'title' => $season->title ?? $targetSeason->title,
                        'source_url' => $season->source_url ?? $targetSeason->source_url,
                        'source_url_hash' => $season->source_url_hash ?? $targetSeason->source_url_hash,
                    ])->save();
                }

                $movedEpisodes += $this->mergeEpisodes($season, $targetSeason);

                LicensedMedia::query()
                    ->where('season_id', $season->id)
                    ->update([
                        'catalog_title_id' => $canonical->id,
                        'season_id' => $targetSeason->id,
                    ]);

                $season->delete();
                $mergedSeasons++;
            }

            LicensedMedia::query()
                ->where('catalog_title_id', $duplicate->id)
                ->update(['catalog_title_id' => $canonical->id]);

            $duplicate->delete();
            $mergedTitles++;
        }

        $canonical->taxonomies()->sync($taxonomyIds);
        $this->refreshCanonicalTitle($canonical, $titles);

        return [
            'titles' => $mergedTitles,
            'seasons' => $mergedSeasons,
            'episodes' => $movedEpisodes,
        ];
    }

    private function mergeEpisodes(Season $fromSeason, Season $targetSeason): int
    {
        $moved = 0;

        foreach ($fromSeason->episodes as $episode) {
            $targetEpisode = Episode::query()
                ->where('season_id', $targetSeason->id)
                ->where('number', $episode->number)
                ->first();

            if ($targetEpisode === null) {
                $episode->season_id = $targetSeason->id;
                $episode->save();

                LicensedMedia::query()
                    ->where('episode_id', $episode->id)
                    ->update([
                        'catalog_title_id' => $targetSeason->catalog_title_id,
                        'season_id' => $targetSeason->id,
                    ]);

                $moved++;

                continue;
            }

            LicensedMedia::query()
                ->where('episode_id', $episode->id)
                ->update([
                    'catalog_title_id' => $targetSeason->catalog_title_id,
                    'season_id' => $targetSeason->id,
                    'episode_id' => $targetEpisode->id,
                ]);

            $targetEpisode->fill([
                'source_page_id' => $targetEpisode->source_page_id ?? $episode->source_page_id,
                'title' => $targetEpisode->title ?? $episode->title,
                'source_url' => $targetEpisode->source_url ?? $episode->source_url,
                'source_url_hash' => $targetEpisode->source_url_hash ?? $episode->source_url_hash,
                'released_at' => $targetEpisode->released_at ?? $episode->released_at,
                'summary' => $targetEpisode->summary ?? $episode->summary,
            ])->save();

            $episode->delete();
            $moved++;
        }

        return $moved;
    }

    /**
     * @param  EloquentCollection<int, CatalogTitle>  $titles
     */
    private function refreshCanonicalTitle(CatalogTitle $canonical, EloquentCollection $titles): void
    {
        $canonical->fill([
            'title' => $this->preferredTitle($canonical->title, $titles->pluck('title')->filter()->all()),
            'year' => $titles->pluck('year')->filter()->min() ?: $canonical->year,
            'poster_url' => $canonical->poster_url ?? $titles->pluck('poster_url')->filter()->first(),
            'description' => $canonical->description ?? $titles->pluck('description')->filter()->first(),
            'original_title' => $this->preferredOriginalTitle($canonical, $titles),
            'indexed_at' => $titles->pluck('indexed_at')->filter()->max() ?: $canonical->indexed_at,
        ])->save();
    }

    /**
     * @param  EloquentCollection<int, CatalogTitle>  $titles
     */
    private function preferredOriginalTitle(CatalogTitle $canonical, EloquentCollection $titles): ?string
    {
        return collect([$canonical->original_title, ...$titles->pluck('original_title')->all()])
            ->filter(fn (?string $title): bool => $title !== null && ! $this->containsCyrillic($title))
            ->first();
    }

    /**
     * @param  list<string>  $titles
     */
    private function preferredTitle(string $currentTitle, array $titles): string
    {
        return collect([$currentTitle, ...$titles])
            ->filter()
            ->sortBy(fn (string $title): int => Str::length($title))
            ->first() ?? $currentTitle;
    }

    private function normalizedSeriesTitleKey(string $title): string
    {
        return Str::lower($this->seriesTitleKey($title));
    }

    private function seriesTitleKey(string $title): string
    {
        $title = Str::squish($title);
        $parts = explode('/', $title, 2);

        if (count($parts) === 2 && $this->containsCyrillic($parts[0]) && $this->containsCyrillic($parts[1])) {
            return Str::squish($parts[0]);
        }

        return $title;
    }

    private function containsCyrillic(string $value): bool
    {
        return preg_match('/\p{Cyrillic}/u', $value) === 1;
    }
}
