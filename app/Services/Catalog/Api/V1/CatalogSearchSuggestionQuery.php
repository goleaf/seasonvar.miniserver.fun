<?php

declare(strict_types=1);

namespace App\Services\Catalog\Api\V1;

use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Catalog\Search\CatalogPeopleLookup;
use App\Services\Catalog\Search\CatalogSearchQueryParser;
use App\Services\Catalog\Search\CatalogTitleSuggestionQuery;
use App\Services\Catalog\Search\HeaderSearchSuggestionCache;
use App\Services\Catalog\Search\PortalSearchSuggestionQuery;
use App\Services\Tags\TagQuery;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

final readonly class CatalogSearchSuggestionQuery
{
    private const LIMIT_PER_TYPE = 5;

    public function __construct(
        private CatalogSearchQueryParser $parser,
        private CatalogTitleSuggestionQuery $titles,
        private CatalogPeopleLookup $people,
        private TagQuery $tags,
        private PortalSearchSuggestionQuery $portal,
        private HeaderSearchSuggestionCache $headerCache,
    ) {}

    /** @return array{query: string, scope: string|null, items: Collection<int, array<string, mixed>>} */
    public function search(string $query, ?User $user, ?string $scope = null): array
    {
        $parsed = $this->parser->parse($query);

        if ($scope === 'header_titles') {
            return [
                'query' => $parsed->raw,
                'scope' => $scope,
                'items' => collect($this->headerCache->remember(
                    $parsed->normalized,
                    fn (): array => $this->titles->search($parsed, null, self::LIMIT_PER_TYPE)
                        ->map(fn (CatalogTitle $title): array => $this->headerTitleItem($title))
                        ->all(),
                    $scope,
                )),
            ];
        }

        if ($scope === 'header_portal') {
            return [
                'query' => $parsed->raw,
                'scope' => $scope,
                'items' => collect($this->headerCache->remember(
                    $parsed->normalized,
                    fn (): array => $this->portal->search($parsed->raw)
                        ->map(static fn (array $item): array => [
                            ...$item,
                            'slug' => '',
                            'title_slug' => null,
                            'count' => 0,
                        ])
                        ->all(),
                    $scope,
                )),
            ];
        }

        $titleItems = $this->titles->search($parsed, $user, self::LIMIT_PER_TYPE)
            ->map(static fn (CatalogTitle $title): array => [
                'type' => 'title',
                'label' => $title->display_title,
                'slug' => (string) $title->slug,
                'title_slug' => (string) $title->slug,
                'count' => 0,
            ]);
        $peopleItems = collect(['actor', 'director'])->flatMap(fn (string $type): Collection => $this->people
            ->search($type, $parsed->raw, $user)
            ->take(self::LIMIT_PER_TYPE)
            ->map(static fn ($person): array => [
                'type' => (string) $person->getAttribute('filter_type'),
                'label' => (string) $person->getAttribute('name'),
                'slug' => (string) $person->getAttribute('slug'),
                'title_slug' => null,
                'count' => (int) $person->getAttribute('public_titles_count'),
            ]));
        $tagItems = $this->tags
            ->searchPublic($parsed->raw, self::LIMIT_PER_TYPE)
            ->map(static fn ($tag): array => [
                'type' => 'tag',
                'public_id' => (string) $tag->public_id,
                'label' => (string) $tag->name,
                'slug' => (string) $tag->slug,
                'title_slug' => null,
                'count' => (int) $tag->public_titles_count,
            ]);

        return [
            'query' => $parsed->raw,
            'scope' => null,
            'items' => $titleItems->concat($peopleItems)->concat($tagItems)->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function headerTitleItem(CatalogTitle $title): array
    {
        $seasons = (int) $title->getAttribute('seasons_count');
        $episodes = (int) $title->getAttribute('episodes_count');
        $details = collect([
            $title->year === null ? null : (string) $title->year,
            trans_choice('catalog.counts.seasons', $seasons, [
                'count' => Number::format($seasons, locale: app()->currentLocale()),
            ]),
            trans_choice('catalog.counts.episodes', $episodes, [
                'count' => Number::format($episodes, locale: app()->currentLocale()),
            ]),
        ])->filter(fn (mixed $value): bool => is_string($value) && $value !== '');

        return [
            'id' => 'title-'.$title->id,
            'type' => 'title',
            'group' => 'titles',
            'label' => $title->display_title,
            'original_title' => $title->original_title !== $title->display_title
                ? $title->original_title
                : null,
            'slug' => (string) $title->slug,
            'title_slug' => (string) $title->slug,
            'url' => route('titles.show', $title),
            'meta' => $details->implode(' · '),
            'poster_url' => $title->poster_url,
            'year' => $title->year,
            'seasons_count' => $seasons,
            'episodes_count' => $episodes,
            'content_type' => (string) $title->type,
            'count' => 0,
        ];
    }
}
