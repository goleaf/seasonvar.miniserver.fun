<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\Models\CatalogCollection;
use App\Models\CatalogTitle;
use App\Models\ReleaseCalendarFeed;
use App\Models\User;
use App\Services\Catalog\Search\CatalogSearchQueryParser;
use App\Services\Catalog\Search\CatalogTitleSearch;
use App\Support\PlainText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

final readonly class ReleaseCalendarFeedManagementQuery
{
    public function __construct(
        private CatalogSearchQueryParser $searchParser,
        private CatalogTitleSearch $titleSearch,
    ) {}

    /** @return Collection<int, ReleaseCalendarFeed> */
    public function feeds(User $user): Collection
    {
        return ReleaseCalendarFeed::query()
            ->whereBelongsTo($user)
            ->with([
                'catalogCollection:id,public_id,name,type,mode',
                'catalogTitle:id,slug,title,original_title',
            ])
            ->latest('created_at')
            ->latest('id')
            ->get();
    }

    /** @return Collection<int, CatalogCollection> */
    public function collections(User $user): Collection
    {
        return CatalogCollection::query()
            ->where('owner_id', $user->id)
            ->orderBy('name')
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'public_id', 'name', 'type', 'mode']);
    }

    /** @return Collection<int, CatalogTitle> */
    public function titles(User $user, string $value): Collection
    {
        $search = PlainText::clean($value, 80);

        if (mb_strlen($search) < 2) {
            return new Collection;
        }

        $limit = 12;
        $candidateIds = $this->candidateIds($search, $limit);

        if ($candidateIds->isNotEmpty()) {
            return CatalogTitle::query()
                ->availableTo($user)
                ->whereKey($candidateIds)
                ->get(['id', 'slug', 'title', 'original_title', 'year'])
                ->sortBy(function (CatalogTitle $title) use ($candidateIds): int {
                    $position = $candidateIds->search($title->id, strict: true);

                    return $position === false ? PHP_INT_MAX : $position;
                })
                ->values();
        }

        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

        return CatalogTitle::query()
            ->availableTo($user)
            ->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw("title LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("original_title LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('aliases', fn (Builder $aliases): Builder => $aliases
                        ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
            })
            ->orderBy('title')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'slug', 'title', 'original_title', 'year']);
    }

    public function title(User $user, int $id): CatalogTitle
    {
        return CatalogTitle::query()
            ->availableTo($user)
            ->findOrFail($id, ['id', 'slug', 'title', 'original_title', 'year']);
    }

    public function collection(User $user, string $publicId): CatalogCollection
    {
        return CatalogCollection::query()
            ->where('owner_id', $user->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    /** @return SupportCollection<int, int> */
    private function candidateIds(string $search, int $limit): SupportCollection
    {
        return $this->titleSearch
            ->candidateQuery($this->searchParser->parse($search))
            ?->limit($limit)
            ->pluck('catalog_title_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values() ?? collect();
    }
}
