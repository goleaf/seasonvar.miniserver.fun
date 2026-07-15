<?php

declare(strict_types=1);

namespace App\Services\Comments;

use App\Enums\CommentTargetType;
use App\Models\CatalogTitle;
use App\Models\Comment;
use App\Models\Episode;
use App\Models\Season;

final class CommentTargetMergeService
{
    public function __construct(
        private readonly CommentSchema $schema,
        private readonly CommentCacheInvalidator $cache,
    ) {}

    public function moveTitle(CatalogTitle $duplicate, CatalogTitle $canonical): void
    {
        if (! $this->schema->available() || $duplicate->is($canonical)) {
            return;
        }

        Comment::query()
            ->withTrashed()
            ->where('target_type', CommentTargetType::Title->value)
            ->where('target_id', $duplicate->id)
            ->update([
                'target_id' => $canonical->id,
                'catalog_title_id' => $canonical->id,
                'updated_at' => now(),
            ]);
        Comment::query()
            ->withTrashed()
            ->where('catalog_title_id', $duplicate->id)
            ->update([
                'catalog_title_id' => $canonical->id,
                'updated_at' => now(),
            ]);
        $this->cache->identitiesChanged([(int) $canonical->id], false);
    }

    public function moveSeason(Season $duplicate, Season $canonical): void
    {
        if (! $this->schema->available() || $duplicate->is($canonical)) {
            return;
        }

        Comment::query()
            ->withTrashed()
            ->where('target_type', CommentTargetType::Season->value)
            ->where('target_id', $duplicate->id)
            ->update([
                'target_id' => $canonical->id,
                'catalog_title_id' => $canonical->catalog_title_id,
                'updated_at' => now(),
            ]);
    }

    public function moveEpisode(Episode $duplicate, Episode $canonical): void
    {
        if (! $this->schema->available() || $duplicate->is($canonical)) {
            return;
        }

        Comment::query()
            ->withTrashed()
            ->where('target_type', CommentTargetType::Episode->value)
            ->where('target_id', $duplicate->id)
            ->update([
                'target_id' => $canonical->id,
                'catalog_title_id' => $canonical->season?->catalog_title_id,
                'updated_at' => now(),
            ]);
    }
}
