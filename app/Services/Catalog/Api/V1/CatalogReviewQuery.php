<?php

declare(strict_types=1);

namespace App\Services\Catalog\Api\V1;

use App\Enums\ReviewOrigin;
use App\Enums\ReviewStatus;
use App\Http\Requests\Api\V1\CatalogReviewIndexRequest;
use App\Models\CatalogTitleReview;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Reviews\ReviewSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class CatalogReviewQuery
{
    public function __construct(
        private CatalogTitleQuery $titles,
        private ReviewSchema $schema,
    ) {}

    /** @return LengthAwarePaginator<int, CatalogTitleReview> */
    public function forTitle(
        string $titleSlug,
        ?User $user,
        CatalogReviewIndexRequest $request,
    ): LengthAwarePaginator {
        $title = $this->titles->visibleTo($user)->where('slug', $titleSlug)->firstOrFail();

        $reviews = $title->reviews()
            ->select(['id', 'catalog_title_id', 'author', 'body', 'published_at'])
            ->when($this->schema->communityAvailable(), fn ($query) => $query
                ->where('origin', ReviewOrigin::Provider->value)
                ->where('status', ReviewStatus::Published->value)
                ->whereNull('deleted_at')
                ->whereNull('merged_into_id'))
            ->latest('published_at')
            ->latest('id');

        return $reviews->paginate($request->perPage())->withQueryString();
    }
}
