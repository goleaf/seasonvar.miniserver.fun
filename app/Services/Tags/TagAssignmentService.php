<?php

declare(strict_types=1);

namespace App\Services\Tags;

use App\Enums\TagSource;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\CatalogTitleTagSource;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class TagAssignmentService
{
    public function __construct(private TagCacheInvalidator $cache) {}

    public function assignGlobal(User $actor, Tag $tag, CatalogTitle $title): void
    {
        Gate::forUser($actor)->authorize('assign', $tag);
        Gate::forUser($actor)->authorize('update', $title);
        abort_unless(CatalogTitle::query()->whereKey($title->id)->exists(), 404);

        if (! Tag::query()->globallyAssignable()->whereKey($tag->id)->exists()) {
            throw ValidationException::withMessages(['tag' => [__('tags.errors.not_assignable')]]);
        }

        $changed = DB::transaction(function () use ($tag, $title): bool {
            $attached = DB::table('catalog_title_tag')->insertOrIgnore([
                'catalog_title_id' => $title->id,
                'tag_id' => $tag->id,
            ]);
            $provenanceChanged = $this->recordEditorialState($title, $tag, true);

            if ($attached < 1 && ! $provenanceChanged) {
                return false;
            }

            $now = now();
            $title->forceFill(['indexed_at' => $now])->touch();
            $this->invalidateRecommendations($title);

            return true;
        }, attempts: 3);

        if ($changed) {
            $this->cache->publicChanged([$title->id]);
        }
    }

    public function removeGlobal(User $actor, Tag $tag, CatalogTitle $title): void
    {
        Gate::forUser($actor)->authorize('assign', $tag);
        Gate::forUser($actor)->authorize('update', $title);

        $changed = DB::transaction(function () use ($tag, $title): bool {
            $detached = DB::table('catalog_title_tag')
                ->where('catalog_title_id', $title->id)
                ->where('tag_id', $tag->id)
                ->delete();
            $provenanceChanged = $this->recordEditorialState($title, $tag, false);

            if ($detached < 1 && ! $provenanceChanged) {
                return false;
            }

            $now = now();
            $title->forceFill(['indexed_at' => $now])->touch();
            $this->invalidateRecommendations($title);

            return true;
        }, attempts: 3);

        if ($changed) {
            $this->cache->publicChanged([$title->id]);
        }
    }

    public function hasEditorialSuppression(CatalogTitle $title, Tag $tag): bool
    {
        return CatalogTitleTagSource::query()
            ->whereBelongsTo($title)
            ->whereBelongsTo($tag)
            ->where('source', TagSource::Editorial->value)
            ->where('is_current', false)
            ->exists();
    }

    private function editorialSourceKey(): string
    {
        return hash('sha256', 'editorial-manual');
    }

    private function recordEditorialState(CatalogTitle $title, Tag $tag, bool $isCurrent): bool
    {
        $observation = CatalogTitleTagSource::query()->firstOrNew([
            'catalog_title_id' => $title->id,
            'tag_id' => $tag->id,
            'source' => TagSource::Editorial->value,
            'source_key' => $this->editorialSourceKey(),
        ]);
        $changed = ! $observation->exists || $observation->is_current !== $isCurrent;

        if (! $changed) {
            return false;
        }

        $now = now();
        $observation->fill([
            'provider' => null,
            'source_id' => null,
            'is_current' => $isCurrent,
            'first_seen_at' => $observation->exists ? $observation->first_seen_at : $now,
            'last_seen_at' => $now,
        ])->save();

        return true;
    }

    private function invalidateRecommendations(CatalogTitle $title): void
    {
        CatalogTitleRecommendation::query()
            ->where('catalog_title_id', $title->id)
            ->orWhere('recommended_title_id', $title->id)
            ->delete();
    }
}
