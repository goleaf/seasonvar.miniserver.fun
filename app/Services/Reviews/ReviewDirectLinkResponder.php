<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\Enums\ReviewStatus;
use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleReviewAlias;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final readonly class ReviewDirectLinkResponder
{
    public function __construct(
        private ReviewSchema $schema,
        private CatalogTitleQuery $titles,
        private CatalogTitleReviewQuery $reviews,
    ) {}

    public function response(Request $request, string $review): RedirectResponse
    {
        abort_unless($this->schema->legacyAvailable() && ctype_digit($review) && (int) $review > 0, 404);
        $reviewId = (int) $review;
        $viewer = $request->user();
        $viewer = $viewer instanceof User ? $viewer : null;
        $record = CatalogTitleReview::query()->find($reviewId);

        if ($record === null && $this->schema->writable()) {
            $alias = CatalogTitleReviewAlias::query()
                ->with('canonicalReview:id,catalog_title_id,status,merged_into_id,deleted_at')
                ->find($reviewId);
            $record = $alias?->canonicalReview;
        }

        abort_unless($record instanceof CatalogTitleReview, 404);

        if ($this->schema->communityAvailable()) {
            $visited = [];

            while ($record->merged_into_id !== null) {
                abort_if(in_array((int) $record->id, $visited, true), 404);
                $visited[] = (int) $record->id;
                $record = CatalogTitleReview::query()->find($record->merged_into_id);
                abort_unless($record instanceof CatalogTitleReview, 404);
            }

            abort_unless(
                $record->status === ReviewStatus::Published
                && $record->deleted_at === null,
                404,
            );
            abort_unless($this->reviews->publicReviewById((int) $record->id, $viewer)->exists(), 404);
        }

        $title = $this->titles->visibleTo($viewer)->find($record->catalog_title_id);
        abort_unless($title !== null, 404);
        $page = $this->reviews->pageForPublicReview($record, $viewer);
        $url = route('titles.show', [
            'catalogTitle' => $title,
            'review' => $record->id,
            'reviewPage' => $page,
        ]).'#review-'.$record->id;

        return redirect()->to($url)->withHeaders([
            'X-Robots-Tag' => 'noindex, follow',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
