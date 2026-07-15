<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\DTOs\Reviews\ReviewAggregateData;
use App\Enums\ReviewOrigin;
use App\Enums\ReviewStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleUserState;
use Illuminate\Support\Facades\DB;

final class ReviewAggregateService
{
    public function __construct(private readonly ReviewSchema $schema) {}

    public function forTitle(CatalogTitle $title): ReviewAggregateData
    {
        if (! $this->schema->communityAvailable()) {
            return new ReviewAggregateData(
                publicCount: CatalogTitleReview::query()->where('catalog_title_id', $title->id)->count(),
                ratedCount: 0,
                ratingAverage: null,
            );
        }

        $reviews = (new CatalogTitleReview)->getTable();
        $states = (new CatalogTitleUserState)->getTable();
        $row = DB::table($reviews.' as reviews')
            ->leftJoin($states.' as rating_state', function ($join): void {
                $join
                    ->on('rating_state.user_id', '=', 'reviews.user_id')
                    ->on('rating_state.catalog_title_id', '=', 'reviews.catalog_title_id');
            })
            ->where('reviews.catalog_title_id', $title->id)
            ->where('reviews.status', ReviewStatus::Published->value)
            ->whereNull('reviews.deleted_at')
            ->whereNull('reviews.merged_into_id')
            ->selectRaw('COUNT(*) AS public_count')
            ->selectRaw(
                'SUM(CASE WHEN reviews.origin = ? AND rating_state.rating IS NOT NULL THEN 1 ELSE 0 END) AS rated_count',
                [ReviewOrigin::User->value],
            )
            ->selectRaw(
                'AVG(CASE WHEN reviews.origin = ? THEN rating_state.rating ELSE NULL END) AS rating_average',
                [ReviewOrigin::User->value],
            )
            ->first();

        return new ReviewAggregateData(
            publicCount: (int) ($row->public_count ?? 0),
            ratedCount: (int) ($row->rated_count ?? 0),
            ratingAverage: $row->rating_average !== null ? round((float) $row->rating_average, 1) : null,
        );
    }
}
