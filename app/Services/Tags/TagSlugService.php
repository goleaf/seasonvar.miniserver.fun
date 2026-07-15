<?php

declare(strict_types=1);

namespace App\Services\Tags;

use App\Models\Tag;
use App\Models\TagAlias;
use App\Models\TagSlug;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class TagSlugService
{
    public function generate(string $label, string $publicId, ?int $exceptTagId = null): string
    {
        $base = Str::limit(Str::slug($label), 150, '');
        $base = $base !== '' ? $base : 'tag-'.Str::lower(Str::replace('-', '', Str::substr($publicId, 0, 12)));

        for ($suffix = 0; $suffix < 100; $suffix++) {
            $candidate = $suffix === 0 ? $base : Str::limit($base, 140, '').'-'.($suffix + 1);

            if (! $this->isTaken($candidate, $exceptTagId)) {
                return $candidate;
            }
        }

        return 'tag-'.Str::lower(Str::replace('-', '', $publicId));
    }

    public function validated(?string $slug, string $label, string $publicId, ?int $exceptTagId = null): string
    {
        $slug = $slug === null || trim($slug) === '' ? $this->generate($label, $publicId, $exceptTagId) : Str::lower(trim($slug));

        if (strlen($slug) > 180 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
            throw ValidationException::withMessages(['slug' => [__('tags.validation.slug')]]);
        }

        if ($this->isTaken($slug, $exceptTagId)) {
            throw ValidationException::withMessages(['slug' => [__('tags.validation.slug_unique')]]);
        }

        return $slug;
    }

    public function change(Tag $tag, string $nextSlug): void
    {
        if ($tag->slug === $nextSlug) {
            return;
        }

        TagSlug::query()->where('slug', $nextSlug)->whereBelongsTo($tag)->delete();
        $history = TagSlug::query()->firstOrCreate([
            'slug' => (string) $tag->slug,
        ], [
            'tag_id' => $tag->id,
        ]);

        if ((int) $history->tag_id !== (int) $tag->id) {
            throw ValidationException::withMessages(['slug' => [__('tags.validation.slug_unique')]]);
        }

        $tag->slug = $nextSlug;
    }

    private function isTaken(string $slug, ?int $exceptTagId): bool
    {
        $current = Tag::query()->where('slug', $slug)
            ->when($exceptTagId !== null, fn ($query) => $query->whereKeyNot($exceptTagId))
            ->exists();

        if ($current || TagSlug::query()->where('slug', $slug)->when(
            $exceptTagId !== null,
            fn ($query) => $query->where('tag_id', '!=', $exceptTagId),
        )->exists()) {
            return true;
        }

        return TagAlias::query()->where('slug', $slug)->when(
            $exceptTagId !== null,
            fn ($query) => $query->where('tag_id', '!=', $exceptTagId),
        )->exists();
    }
}
