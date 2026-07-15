<?php

declare(strict_types=1);

namespace App\Services\Tags;

use App\Models\CatalogTitle;
use App\Models\User;
use App\Models\UserTag;
use App\Services\Catalog\CatalogTaxonomyRegistry;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Catalog\CatalogUserCardStateLoader;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class PersonalTagLibraryQuery
{
    public function __construct(
        private TagQuery $tags,
        private CatalogTitleQuery $titles,
        private CatalogTaxonomyRegistry $taxonomies,
        private CatalogUserCardStateLoader $cardStates,
    ) {}

    /** @return Collection<int, UserTag> */
    public function active(User $user, string $search = ''): Collection
    {
        return $this->tags->personal($user, $search);
    }

    /** @return Collection<int, UserTag> */
    public function restorable(User $user): Collection
    {
        return UserTag::query()
            ->onlyTrashed()
            ->ownedBy($user)
            ->where('deleted_at', '>=', now()->subDays(max(1, (int) config('tags.restoration_days', 30))))
            ->select(['id', 'public_id', 'user_id', 'name', 'description', 'content_locale', 'content_version', 'deleted_at'])
            ->orderByDesc('deleted_at')
            ->limit(50)
            ->get();
    }

    public function owned(User $user, string $publicId, bool $withTrashed = false): ?UserTag
    {
        if (preg_match('/^[a-f0-9-]{36}$/iD', $publicId) !== 1) {
            return null;
        }

        return UserTag::query()
            ->when($withTrashed, fn (Builder $query): Builder => $query->withTrashed())
            ->ownedBy($user)
            ->where('public_id', $publicId)
            ->first();
    }

    /** @return list<string> */
    public function assignedPublicIds(User $user, CatalogTitle $title): array
    {
        return UserTag::query()
            ->ownedBy($user)
            ->join('catalog_title_user_tag as personal_assignment', 'personal_assignment.user_tag_id', '=', 'user_tags.id')
            ->where('personal_assignment.catalog_title_id', $title->id)
            ->orderBy('personal_assignment.position')
            ->orderBy('user_tags.id')
            ->pluck('user_tags.public_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    /** @return LengthAwarePaginator<int, CatalogTitle> */
    public function titles(User $user, UserTag $tag, int $perPage = 24): LengthAwarePaginator
    {
        /** @var array<int|string, string|\Closure(\Illuminate\Database\Eloquent\Relations\Relation<*, *, *>): mixed> $cardLoads */
        $cardLoads = $this->taxonomies->cardSummaryLoads();
        $query = $this->titles->visibleTo($user)
            ->select(['id', 'slug', 'title', 'original_title', 'type', 'year', 'poster_url', 'indexed_at', 'description'])
            ->whereHas('personalTags', fn (Builder $query): Builder => $query
                ->where('user_tags.user_id', $user->id)
                ->where('user_tags.id', $tag->id))
            ->with($cardLoads)
            ->withCount($this->titles->publicCardCounts($user))
            ->orderByDesc('indexed_at')
            ->orderByDesc('id');
        $paginator = $query->paginate(max(1, min(48, $perPage)), pageName: 'tagPage')->withQueryString();
        $paginator->setCollection($this->cardStates->load($paginator->getCollection(), $user));

        return $paginator;
    }
}
