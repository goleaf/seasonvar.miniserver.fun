<?php

declare(strict_types=1);

namespace App\Services\Catalog\Api\V1;

use App\Http\Requests\Api\V1\CatalogReviewIndexRequest;
use App\Models\CatalogTitleReview;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class CatalogReviewQuery
{
    public function __construct(private CatalogTitleQuery $titles) {}

    /** @return LengthAwarePaginator<int, CatalogTitleReview> */
    public function forTitle(
        string $titleSlug,
        ?User $user,
        CatalogReviewIndexRequest $request,
    ): LengthAwarePaginator {
        $title = $this->titles->visibleTo($user)->where('slug', $titleSlug)->firstOrFail();

        return $title->reviews()
            ->select(['id', 'catalog_title_id', 'author', 'body', 'published_at'])
            ->latest('published_at')
            ->latest('id')
            ->paginate($request->perPage())
            ->withQueryString();
    }
}
