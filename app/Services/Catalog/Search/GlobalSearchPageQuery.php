<?php

declare(strict_types=1);

namespace App\Services\Catalog\Search;

use App\Models\CatalogTitle;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Throwable;

final readonly class GlobalSearchPageQuery
{
    public function __construct(
        private CatalogSearchQueryParser $parser,
        private CatalogTitleSuggestionQuery $titles,
        private PortalSearchSuggestionQuery $portal,
        private CatalogSearchSuggestion $suggestions,
    ) {}

    /**
     * @return array{
     *     titles: Collection<int, CatalogTitle>,
     *     title_count: int,
     *     title_count_label: string,
     *     portal_count: string,
     *     portal_groups: Collection<int, array{key: string, label: string, items: Collection<int, array{id: string, type: string, group: string, label: string, url: string, meta: string, rank: int}>}>,
     *     search_suggestions: Collection<int, CatalogTitle>,
     *     failed: bool
     * }
     */
    public function search(string $query, ?User $user): array
    {
        if ($query === '') {
            return $this->empty();
        }

        try {
            $parsed = $this->parser->parse($query);
            $titleCount = $this->titles->count($parsed, $user);
            $portal = $this->portal->search($query, 30);
            $grouped = $portal->groupBy('group');
            $groups = collect([
                'people' => __('catalog.header_search.groups.people'),
                'directories' => __('catalog.header_search.groups.directories'),
                'community' => __('catalog.header_search.groups.community'),
                'sections' => __('catalog.header_search.groups.sections'),
            ])->map(fn (string $label, string $key): array => [
                'key' => $key,
                'label' => $label,
                'items' => $grouped->get($key, collect()),
            ])->filter(fn (array $group): bool => $group['items']->isNotEmpty())->values();

            return [
                'titles' => $this->titles->search($parsed, $user, 12),
                'title_count' => $titleCount,
                'title_count_label' => trans_choice('catalog.counts.results_found', $titleCount, [
                    'count' => Number::format($titleCount, locale: app()->currentLocale()),
                ]),
                'portal_count' => Number::format($portal->count(), locale: app()->currentLocale()),
                'portal_groups' => $groups,
                'search_suggestions' => $titleCount === 0
                    ? $this->suggestions->forQuery($parsed, $user)
                    : collect(),
                'failed' => false,
            ];
        } catch (Throwable $exception) {
            Log::warning('Global portal search query failed.', [
                'exception_class' => $exception::class,
            ]);

            return $this->empty(failed: true);
        }
    }

    /**
     * @return array{
     *     titles: Collection<int, CatalogTitle>,
     *     title_count: int,
     *     title_count_label: string,
     *     portal_count: string,
     *     portal_groups: Collection<int, array{key: string, label: string, items: Collection<int, array{id: string, type: string, group: string, label: string, url: string, meta: string, rank: int}>}>,
     *     search_suggestions: Collection<int, CatalogTitle>,
     *     failed: bool
     * }
     */
    private function empty(bool $failed = false): array
    {
        return [
            'titles' => collect(),
            'title_count' => 0,
            'title_count_label' => trans_choice('catalog.counts.results_found', 0, [
                'count' => Number::format(0, locale: app()->currentLocale()),
            ]),
            'portal_count' => Number::format(0, locale: app()->currentLocale()),
            'portal_groups' => collect(),
            'search_suggestions' => collect(),
            'failed' => $failed,
        ];
    }
}
