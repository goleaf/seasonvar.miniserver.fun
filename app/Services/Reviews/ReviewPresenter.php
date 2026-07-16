<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\DTOs\Reviews\PublicReviewActivityData;
use App\DTOs\Reviews\ReviewItemData;
use App\DTOs\Reviews\ReviewViewerContext;
use App\Enums\ReviewOrigin;
use App\Enums\ReviewStatus;
use App\Enums\ReviewTargetType;
use App\Enums\ReviewVoteType;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleReview;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class ReviewPresenter
{
    public function __construct(private readonly ReviewSchema $schema) {}

    /**
     * @param  Collection<int, CatalogTitleReview>  $reviews
     * @param  array<int, ReviewVoteType>  $viewerVotes
     * @param  list<int>  $revealedReviewIds
     * @return Collection<int, ReviewItemData>
     */
    public function collection(
        Collection $reviews,
        ?User $viewer,
        ReviewViewerContext $context,
        array $viewerVotes,
        array $revealedReviewIds,
        ?int $highlightedReviewId = null,
    ): Collection {
        return $reviews->map(fn (CatalogTitleReview $review): ReviewItemData => $this->item(
            $review,
            $viewer,
            $context,
            $viewerVotes[(int) $review->id] ?? null,
            in_array((int) $review->id, $revealedReviewIds, true),
            $highlightedReviewId === (int) $review->id,
        ));
    }

    public function item(
        CatalogTitleReview $review,
        ?User $viewer,
        ReviewViewerContext $context,
        ?ReviewVoteType $viewerVote,
        bool $revealed,
        bool $highlighted = false,
    ): ReviewItemData {
        $community = $this->schema->communityAvailable();
        $writable = $this->schema->writable();
        $status = $community ? $review->status : ReviewStatus::Published;
        $origin = $review->getAttribute('origin') instanceof ReviewOrigin
            ? $review->origin
            : ReviewOrigin::Provider;
        $isOwn = $viewer !== null && $review->user_id !== null && (int) $review->user_id === (int) $viewer->id;
        $isDeleted = $community && $review->isDeleted();
        $bodyHidden = (bool) ($review->is_spoiler ?? false) && ! $revealed;
        $author = $review->relationLoaded('authorAccount') ? $review->authorAccount : null;
        $authorName = $author instanceof User
            ? $author->name
            : ($origin === ReviewOrigin::User
                ? __('reviews.author.deleted')
                : (filled($review->author) ? (string) $review->author : __('reviews.author.provider')));
        $title = $review->relationLoaded('catalogTitle') ? $review->catalogTitle : null;
        $helpfulCount = (int) ($review->getAttribute('helpful_count') ?? 0);
        $notHelpfulCount = (int) ($review->getAttribute('not_helpful_count') ?? 0);
        $rating = $review->getAttribute('review_rating');
        $rating = is_numeric($rating) ? (int) $rating : null;
        $date = $review->published_at ?? $review->created_at;
        $canView = $community ? Gate::forUser($viewer)->allows('view', $review) : true;
        $canReveal = $bodyHidden && $canView;
        $targetUrl = $title instanceof CatalogTitle ? route('titles.show', $title) : null;
        $directUrl = ! $community || (
            $status === ReviewStatus::Published
            && ! $isDeleted
            && $review->merged_into_id === null
        )
            ? route('reviews.show', ['review' => $review->id])
            : null;

        return new ReviewItemData(
            id: (int) $review->id,
            origin: $origin->value,
            scopeLabel: ReviewTargetType::Title->label(),
            authorName: $authorName,
            authorInitial: Str::upper(Str::substr($authorName, 0, 1)),
            authorProfileUrl: $author instanceof User
                && ! $context->hides($review->user_id)
                && $author->relationLoaded('profile')
                && $author->profile?->isPublic()
                    ? route('users.show', ['username' => $author->profile->username])
                    : null,
            title: ! $bodyHidden && filled($review->review_title)
                ? (string) $review->review_title
                : null,
            body: $bodyHidden || ! $canView ? null : (string) $review->body,
            bodyHidden: $bodyHidden,
            isSpoiler: (bool) ($review->is_spoiler ?? false),
            isVerifiedWatching: (bool) ($review->is_verified_watch ?? false),
            rating: $rating,
            ratingMaximum: max(1, (int) config('catalog.user_rating.maximum', 10)),
            status: $status->value,
            statusLabel: $status->label(),
            publishedLabel: $date?->translatedFormat('d.m.Y H:i') ?? __('reviews.dates.unknown'),
            isEdited: $review->edited_at !== null,
            isDeleted: $isDeleted,
            isOwn: $isOwn,
            helpfulCount: $helpfulCount,
            notHelpfulCount: $notHelpfulCount,
            helpfulnessScore: $helpfulCount - $notHelpfulCount,
            viewerVote: $viewerVote?->value,
            directUrl: $directUrl,
            targetUrl: $targetUrl,
            targetTitle: $title instanceof CatalogTitle ? $title->display_title : null,
            isHighlighted: $highlighted,
            canReveal: $canReveal,
            canEdit: $writable
                && ! $context->isReviewRestricted
                && Gate::forUser($viewer)->allows('update', $review),
            canDelete: $writable && Gate::forUser($viewer)->allows('delete', $review),
            canRestore: $writable
                && ! $context->isReviewRestricted
                && Gate::forUser($viewer)->allows('restore', $review),
            canVote: $writable
                && ! $context->isReviewRestricted
                && ! $context->hides($review->user_id)
                && Gate::forUser($viewer)->allows('vote', $review),
            canReport: $writable
                && ! $context->isReviewRestricted
                && ! $context->hides($review->user_id)
                && Gate::forUser($viewer)->allows('report', $review),
            canModerate: $writable && Gate::forUser($viewer)->allows('moderate', $review),
            version: (int) ($review->version ?? 1),
        );
    }

    public function publicAuthorItem(CatalogTitleReview $review): PublicReviewActivityData
    {
        $title = $review->relationLoaded('catalogTitle') ? $review->catalogTitle : null;
        $isSpoiler = (bool) $review->is_spoiler;

        return new PublicReviewActivityData(
            id: (int) $review->id,
            title: $isSpoiler || ! filled($review->review_title)
                ? null
                : (string) $review->review_title,
            excerpt: $isSpoiler ? null : Str::limit((string) $review->body, 420),
            isSpoiler: $isSpoiler,
            targetTitle: $title instanceof CatalogTitle ? $title->display_title : null,
            targetUrl: $title instanceof CatalogTitle ? route('titles.show', $title) : null,
            directUrl: route('reviews.show', ['review' => $review->id]),
            publishedAt: ($review->published_at ?? $review->created_at)?->diffForHumans() ?? '',
        );
    }
}
