<?php

declare(strict_types=1);

namespace App\Services\Tags;

use App\DTOs\TagPageData;
use App\Enums\TagModerationStatus;
use App\Models\Tag;
use App\Support\PlainText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final readonly class TagPagePresenter
{
    public function __construct(private TagQuery $tags) {}

    /** @param Collection<string, Model> $activeTaxonomies */
    public function present(?string $routeFilterType, Collection $activeTaxonomies, int $publicTitleCount): ?TagPageData
    {
        if ($routeFilterType !== 'tag') {
            return null;
        }

        $selected = $activeTaxonomies->get('tag');

        if (! $selected instanceof Tag || ! Tag::usesCanonicalSchema()) {
            return null;
        }

        $tag = $this->tags->publicTags()
            ->with(['aliases' => fn ($query) => $query
                ->whereIn('locale', ['und', ...$this->tags->contentLocales()])
                ->where('moderation_status', TagModerationStatus::Approved->value)
                ->select(['id', 'tag_id', 'locale', 'name'])
                ->orderBy('locale')
                ->orderBy('name')])
            ->whereKey($selected->id)
            ->first();

        if (! $tag instanceof Tag) {
            return null;
        }

        $translation = $tag->translations->firstWhere('locale', app()->getLocale())
            ?? $tag->translations->firstWhere('locale', (string) config('app.fallback_locale', 'ru'));
        $related = $this->tags->related($tag)
            ->map(fn (Tag $related): array => [
                'public_id' => (string) $related->public_id,
                'name' => (string) $related->name,
                'slug' => (string) $related->slug,
                'count' => (int) $related->public_titles_count,
            ])
            ->values()
            ->all();

        return new TagPageData(
            publicId: (string) $tag->public_id,
            name: (string) $tag->name,
            slug: (string) $tag->slug,
            type: $tag->type->value,
            shortDescription: $this->optionalPlainText($translation?->short_description, 500),
            description: $this->optionalPlainText($translation?->description, 10_000),
            seoTitle: $this->optionalPlainText($translation?->seo_title, 180),
            seoDescription: $this->optionalPlainText($translation?->seo_description, 320),
            aliases: $tag->aliases
                ->pluck('name')
                ->map(fn (mixed $name): string => PlainText::clean($name, 80))
                ->filter()
                ->unique(fn (string $name): string => mb_strtolower($name))
                ->values()
                ->all(),
            related: $related,
            publicTitleCount: max(0, $publicTitleCount),
        );
    }

    private function optionalPlainText(mixed $value, int $limit): ?string
    {
        $value = PlainText::clean($value, $limit);

        return $value === '' ? null : $value;
    }
}
