<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\Enums\ReviewTargetType;
use App\Exceptions\Reviews\ReviewActionException;
use App\Models\CatalogTitleReview;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery;
use App\ValueObjects\ReviewTarget;

final class ReviewTargetResolver
{
    public function __construct(private readonly CatalogTitleQuery $titles) {}

    public function resolve(ReviewTargetType|string $type, int $targetId, ?User $viewer): ReviewTarget
    {
        $type = is_string($type) ? ReviewTargetType::tryFrom($type) : $type;

        if ($type !== ReviewTargetType::Title || $targetId < 1) {
            throw new ReviewActionException('reviews.errors.invalid_target');
        }

        $title = $this->titles->visibleTo($viewer)->find($targetId);

        if ($title === null) {
            throw new ReviewActionException('reviews.errors.invalid_target');
        }

        return new ReviewTarget($type, (int) $title->id, (int) $title->id);
    }

    public function fromReview(CatalogTitleReview $review, ?User $viewer): ReviewTarget
    {
        return $this->resolve(ReviewTargetType::Title, (int) $review->catalog_title_id, $viewer);
    }
}
