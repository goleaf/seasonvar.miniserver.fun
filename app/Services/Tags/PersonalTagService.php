<?php

declare(strict_types=1);

namespace App\Services\Tags;

use App\DTOs\PersonalTagData;
use App\Models\CatalogTitle;
use App\Models\User;
use App\Models\UserTag;
use App\Support\PlainText;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final readonly class PersonalTagService
{
    public function __construct(
        private TagNormalizationService $normalizer,
        private TagCacheInvalidator $cache,
    ) {}

    public function create(User $owner, PersonalTagData $data): UserTag
    {
        Gate::forUser($owner)->authorize('create', UserTag::class);
        $this->purgeExpired($owner);
        $prepared = $this->prepared($data);
        $existing = UserTag::query()
            ->withTrashed()
            ->ownedBy($owner)
            ->where('normalized_name_hash', $prepared['normalized_name_hash'])
            ->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                throw ValidationException::withMessages(['name' => [__('tags.errors.duplicate_deleted')]]);
            }

            return $existing;
        }

        $rateKey = 'personal-tag-create:'.$owner->getKey();
        $allowed = RateLimiter::attempt(
            $rateKey,
            max(1, (int) config('tags.creation_rate_limit', 20)),
            static fn (): bool => true,
            max(60, (int) config('tags.creation_rate_decay_seconds', 3_600)),
        );

        if (! $allowed) {
            throw ValidationException::withMessages(['name' => [__('tags.errors.rate_limited')]]);
        }

        if (UserTag::query()->ownedBy($owner)->count() >= max(1, (int) config('tags.personal_tags_per_user', 250))) {
            throw ValidationException::withMessages(['name' => [__('tags.errors.personal_limit')]]);
        }

        try {
            $tag = $owner->personalTags()->create($prepared);
        } catch (UniqueConstraintViolationException) {
            $tag = UserTag::query()
                ->ownedBy($owner)
                ->where('normalized_name_hash', $prepared['normalized_name_hash'])
                ->first();

            if ($tag === null) {
                throw ValidationException::withMessages(['name' => [__('tags.errors.duplicate')]]);
            }
        }

        $this->cache->personalChanged($owner);

        return $tag;
    }

    public function update(User $owner, UserTag $tag, PersonalTagData $data, ?int $expectedVersion = null): UserTag
    {
        Gate::forUser($owner)->authorize('update', $tag);
        $prepared = $this->prepared($data);
        try {
            $updated = DB::transaction(function () use ($owner, $tag, $prepared, $expectedVersion): UserTag {
                $locked = UserTag::query()->ownedBy($owner)->lockForUpdate()->findOrFail($tag->id);

                if ($expectedVersion !== null && $locked->content_version !== $expectedVersion) {
                    throw ValidationException::withMessages(['form' => [__('tags.errors.stale_edit')]]);
                }

                $duplicate = UserTag::query()
                    ->withTrashed()
                    ->ownedBy($owner)
                    ->whereKeyNot($locked->id)
                    ->where('normalized_name_hash', $prepared['normalized_name_hash'])
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages(['name' => [__('tags.errors.duplicate')]]);
                }

                $locked->forceFill([
                    ...$prepared,
                    'content_version' => $locked->content_version + 1,
                ])->save();

                return $locked;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => [__('tags.errors.duplicate')]]);
        }

        $this->cache->personalChanged($owner);

        return $updated->refresh();
    }

    public function delete(User $owner, UserTag $tag): void
    {
        Gate::forUser($owner)->authorize('delete', $tag);
        $changed = DB::transaction(function () use ($owner, $tag): bool {
            $locked = UserTag::query()
                ->withTrashed()
                ->ownedBy($owner)
                ->lockForUpdate()
                ->findOrFail($tag->id);

            if ($locked->trashed()) {
                return false;
            }

            $locked->content_version++;
            $locked->save();
            $locked->delete();

            return true;
        }, attempts: 3);

        if ($changed) {
            $this->cache->personalChanged($owner);
        }
    }

    public function restore(User $owner, UserTag $tag): UserTag
    {
        Gate::forUser($owner)->authorize('restore', $tag);
        $days = max(1, (int) config('tags.restoration_days', 30));
        $result = DB::transaction(function () use ($owner, $tag, $days): array {
            $locked = UserTag::query()
                ->withTrashed()
                ->ownedBy($owner)
                ->lockForUpdate()
                ->findOrFail($tag->id);

            if (! $locked->trashed()) {
                return ['tag' => $locked, 'changed' => false];
            }

            if ($locked->deleted_at?->lt(now()->subDays($days))) {
                throw ValidationException::withMessages(['tag' => [__('tags.errors.restore_expired')]]);
            }

            $duplicate = UserTag::query()
                ->ownedBy($owner)
                ->whereKeyNot($locked->id)
                ->where('normalized_name_hash', $locked->normalized_name_hash)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages(['name' => [__('tags.errors.restore_conflict')]]);
            }

            $locked->restore();
            $locked->forceFill(['content_version' => $locked->content_version + 1])->save();

            return ['tag' => $locked, 'changed' => true];
        }, attempts: 3);

        if ($result['changed']) {
            $this->cache->personalChanged($owner);
        }

        return $result['tag']->refresh();
    }

    /**
     * @param  array<array-key, mixed>  $publicIds
     * @return array{added: list<int>, removed: list<int>, selected: list<int>, changed: bool}
     */
    public function reconcileAssignments(User $owner, CatalogTitle $title, array $publicIds): array
    {
        Gate::forUser($owner)->authorize('interact', $title);
        $publicIds = collect($publicIds)
            ->map(function (mixed $id): string {
                if (! is_string($id) || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $id) !== 1) {
                    throw ValidationException::withMessages(['tags' => [__('tags.errors.unauthorized_assignment')]]);
                }

                return mb_strtolower($id);
            })
            ->unique()
            ->values();
        $limit = max(1, (int) config('tags.personal_assignment_limit', 50));

        if ($publicIds->count() > $limit) {
            throw ValidationException::withMessages(['tags' => [__('tags.errors.assignment_limit', ['count' => $limit])]]);
        }

        $selected = UserTag::query()
            ->ownedBy($owner)
            ->whereIn('public_id', $publicIds)
            ->get(['id', 'public_id']);

        if ($selected->count() !== $publicIds->count()) {
            throw ValidationException::withMessages(['tags' => [__('tags.errors.unauthorized_assignment')]]);
        }

        $idsByPublicId = $selected->pluck('id', 'public_id');
        $orderedIds = $publicIds->map(fn (string $id): int => (int) $idsByPublicId->get($id))->all();
        $result = DB::transaction(function () use ($owner, $title, $orderedIds): array {
            $activeOwnerTagIds = UserTag::query()->ownedBy($owner)->select('id');
            $currentIds = DB::table('catalog_title_user_tag')
                ->where('catalog_title_id', $title->id)
                ->whereIn('user_tag_id', $activeOwnerTagIds)
                ->orderBy('position')
                ->orderBy('user_tag_id')
                ->pluck('user_tag_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            $removed = array_values(array_diff($currentIds, $orderedIds));
            $added = array_values(array_diff($orderedIds, $currentIds));

            if ($currentIds === $orderedIds) {
                return ['added' => [], 'removed' => [], 'selected' => $orderedIds, 'changed' => false];
            }

            if ($removed !== []) {
                DB::table('catalog_title_user_tag')
                    ->where('catalog_title_id', $title->id)
                    ->whereIn('user_tag_id', $removed)
                    ->delete();
            }

            $now = now();

            foreach ($orderedIds as $position => $tagId) {
                DB::table('catalog_title_user_tag')->insertOrIgnore([
                    'user_tag_id' => $tagId,
                    'catalog_title_id' => $title->id,
                    'position' => $position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('catalog_title_user_tag')
                    ->where('user_tag_id', $tagId)
                    ->where('catalog_title_id', $title->id)
                    ->update(['position' => $position, 'updated_at' => $now]);
            }

            return ['added' => $added, 'removed' => $removed, 'selected' => $orderedIds, 'changed' => true];
        }, attempts: 3);

        if ($result['changed']) {
            $this->cache->personalChanged($owner);
        }

        return $result;
    }

    public function removeAssignment(User $owner, UserTag $tag, CatalogTitle $title): void
    {
        Gate::forUser($owner)->authorize('assign', $tag);
        Gate::forUser($owner)->authorize('interact', $title);
        $deleted = DB::table('catalog_title_user_tag')
            ->where('user_tag_id', $tag->id)
            ->where('catalog_title_id', $title->id)
            ->delete();

        if ($deleted > 0) {
            $this->cache->personalChanged($owner);
        }
    }

    public function purgeExpired(User $owner): int
    {
        $cutoff = now()->subDays(max(1, (int) config('tags.restoration_days', 30)));

        $count = UserTag::query()
            ->onlyTrashed()
            ->ownedBy($owner)
            ->where('deleted_at', '<', $cutoff)
            ->limit(50)
            ->get()
            ->each->forceDelete()
            ->count();

        if ($count > 0) {
            $this->cache->personalChanged($owner);
        }

        return $count;
    }

    /** @return array{name: string, normalized_name: string, normalized_name_hash: string, description: string|null, content_locale: string|null} */
    private function prepared(PersonalTagData $data): array
    {
        if ($this->normalizer->containsUnsafeInput($data->name)) {
            throw ValidationException::withMessages(['name' => [__('tags.validation.name', [
                'min' => config('tags.label_min_length', 2),
                'max' => config('tags.label_max_length', 80),
            ])]]);
        }

        $name = $this->normalizer->display($data->name);
        $minimum = max(1, (int) config('tags.label_min_length', 2));
        $maximum = max($minimum, (int) config('tags.label_max_length', 80));

        if (mb_strlen($name) < $minimum || mb_strlen($name) > $maximum || ! $this->normalizer->hasMeaningfulContent($name)) {
            throw ValidationException::withMessages(['name' => [__('tags.validation.name', ['min' => $minimum, 'max' => $maximum])]]);
        }

        if ($this->normalizer->isReserved($name, $data->contentLocale)) {
            throw ValidationException::withMessages(['name' => [__('tags.validation.reserved')]]);
        }

        $description = PlainText::clean($data->description);
        $description = $description === '' ? null : $description;
        $descriptionMaximum = max(1, (int) config('tags.personal_description_max_length', 1_000));

        if ($description !== null && mb_strlen($description) > $descriptionMaximum) {
            throw ValidationException::withMessages(['description' => [__('tags.validation.description', ['max' => $descriptionMaximum])]]);
        }

        $supportedLocales = config('tags.supported_locales', []);

        if ($data->contentLocale !== null && ! in_array($data->contentLocale, $supportedLocales, true)) {
            throw ValidationException::withMessages(['contentLocale' => [__('tags.validation.locale')]]);
        }

        $locale = $data->contentLocale;
        $normalized = $this->normalizer->comparison($name);

        return [
            'name' => $name,
            'normalized_name' => $normalized,
            'normalized_name_hash' => hash('sha256', $normalized),
            'description' => $description,
            'content_locale' => $locale,
        ];
    }
}
