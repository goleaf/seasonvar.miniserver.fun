<?php

declare(strict_types=1);

namespace App\Services\Tags;

use App\Models\CatalogTitle;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

final readonly class TagTitleMergeService
{
    public function __construct(private TagCacheInvalidator $cache) {}

    public function moveTitle(CatalogTitle $canonical, CatalogTitle $duplicate): void
    {
        if (! Tag::usesCanonicalSchema()) {
            return;
        }

        $this->moveGlobalAssignments($canonical, $duplicate);
        $this->moveProvenance($canonical, $duplicate);
        $this->movePersonalAssignments($canonical, $duplicate);
    }

    private function moveGlobalAssignments(CatalogTitle $canonical, CatalogTitle $duplicate): void
    {
        $tagIds = DB::table('catalog_title_tag')
            ->where('catalog_title_id', $duplicate->id)
            ->pluck('tag_id');

        foreach ($tagIds as $tagId) {
            DB::table('catalog_title_tag')->insertOrIgnore([
                'catalog_title_id' => $canonical->id,
                'tag_id' => (int) $tagId,
            ]);
        }
    }

    private function moveProvenance(CatalogTitle $canonical, CatalogTitle $duplicate): void
    {
        $rows = DB::table('catalog_title_tag_sources')
            ->where('catalog_title_id', $duplicate->id)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $identity = [
                'catalog_title_id' => $canonical->id,
                'tag_id' => (int) $row->tag_id,
                'source' => (string) $row->source,
                'source_key' => (string) $row->source_key,
            ];
            $existing = DB::table('catalog_title_tag_sources')->where($identity)->first();

            if ($existing === null) {
                DB::table('catalog_title_tag_sources')
                    ->where('id', $row->id)
                    ->update(['catalog_title_id' => $canonical->id, 'updated_at' => now()]);

                continue;
            }

            DB::table('catalog_title_tag_sources')
                ->where('id', $existing->id)
                ->update([
                    'provider' => $existing->provider ?? $row->provider,
                    'source_id' => $existing->source_id ?? $row->source_id,
                    'is_current' => (bool) $existing->is_current || (bool) $row->is_current,
                    'first_seen_at' => $this->earliest($existing->first_seen_at, $row->first_seen_at),
                    'last_seen_at' => $this->latest($existing->last_seen_at, $row->last_seen_at),
                    'updated_at' => now(),
                ]);
            DB::table('catalog_title_tag_sources')->where('id', $row->id)->delete();
        }
    }

    private function movePersonalAssignments(CatalogTitle $canonical, CatalogTitle $duplicate): void
    {
        $rows = DB::table('catalog_title_user_tag')
            ->join('user_tags', 'user_tags.id', '=', 'catalog_title_user_tag.user_tag_id')
            ->where('catalog_title_user_tag.catalog_title_id', $duplicate->id)
            ->orderBy('catalog_title_user_tag.user_tag_id')
            ->get([
                'catalog_title_user_tag.user_tag_id',
                'catalog_title_user_tag.position',
                'catalog_title_user_tag.created_at',
                'catalog_title_user_tag.updated_at',
                'user_tags.user_id',
            ]);
        $ownerIds = [];

        foreach ($rows as $row) {
            $existing = DB::table('catalog_title_user_tag')
                ->where('user_tag_id', $row->user_tag_id)
                ->where('catalog_title_id', $canonical->id)
                ->first();

            if ($existing === null) {
                DB::table('catalog_title_user_tag')
                    ->where('user_tag_id', $row->user_tag_id)
                    ->where('catalog_title_id', $duplicate->id)
                    ->update([
                        'catalog_title_id' => $canonical->id,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('catalog_title_user_tag')
                    ->where('user_tag_id', $row->user_tag_id)
                    ->where('catalog_title_id', $canonical->id)
                    ->update([
                        'position' => min((int) $existing->position, (int) $row->position),
                        'created_at' => $this->earliest($existing->created_at, $row->created_at),
                        'updated_at' => now(),
                    ]);
                DB::table('catalog_title_user_tag')
                    ->where('user_tag_id', $row->user_tag_id)
                    ->where('catalog_title_id', $duplicate->id)
                    ->delete();
            }

            $ownerIds[] = (int) $row->user_id;
        }

        foreach (array_unique($ownerIds) as $ownerId) {
            $this->normalizePersonalPositions($canonical, $ownerId);
            $this->cache->personalChangedId($ownerId);
        }
    }

    private function normalizePersonalPositions(CatalogTitle $title, int $ownerId): void
    {
        $tagIds = DB::table('catalog_title_user_tag')
            ->join('user_tags', 'user_tags.id', '=', 'catalog_title_user_tag.user_tag_id')
            ->where('catalog_title_user_tag.catalog_title_id', $title->id)
            ->where('user_tags.user_id', $ownerId)
            ->orderBy('catalog_title_user_tag.position')
            ->orderBy('catalog_title_user_tag.user_tag_id')
            ->pluck('catalog_title_user_tag.user_tag_id');
        $now = now();

        foreach ($tagIds as $position => $tagId) {
            DB::table('catalog_title_user_tag')
                ->where('user_tag_id', $tagId)
                ->where('catalog_title_id', $title->id)
                ->update([
                    'position' => $position,
                    'updated_at' => $now,
                ]);
        }
    }

    private function earliest(mixed $left, mixed $right): mixed
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return (string) $left <= (string) $right ? $left : $right;
    }

    private function latest(mixed $left, mixed $right): mixed
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return (string) $left >= (string) $right ? $left : $right;
    }
}
