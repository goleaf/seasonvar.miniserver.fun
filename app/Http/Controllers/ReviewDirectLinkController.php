<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReviewStatus;
use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleReviewAlias;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Reviews\CatalogTitleReviewQuery;
use App\Services\Reviews\ReviewSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ReviewDirectLinkController extends Controller
{
    public function __invoke(
        Request $request,
        string $review,
        ReviewSchema $schema,
        CatalogTitleQuery $titles,
        CatalogTitleReviewQuery $reviews,
    ): RedirectResponse {
        abort_unless($schema->legacyAvailable() && ctype_digit($review) && (int) $review > 0, 404);
        $reviewId = (int) $review;
        $viewer = $request->user();
        $viewer = $viewer instanceof User ? $viewer : null;
        $record = CatalogTitleReview::query()->find($reviewId);

        if ($record === null && $schema->writable()) {
            $alias = CatalogTitleReviewAlias::query()
                ->with('canonicalReview')
                ->find($reviewId);
            $record = $alias?->canonicalReview;
        }

        abort_unless($record instanceof CatalogTitleReview, 404);

        if ($schema->communityAvailable()) {
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
            abort_unless($reviews->publicReviewById((int) $record->id, $viewer)->exists(), 404);
        }

        $title = $titles->visibleTo($viewer)->find($record->catalog_title_id);
        abort_unless($title !== null, 404);
        $page = $reviews->pageForPublicReview($record, $viewer);
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
