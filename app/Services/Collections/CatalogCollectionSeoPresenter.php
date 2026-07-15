<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionTranslation;
use App\Models\User;
use App\Support\PlainText;

final class CatalogCollectionSeoPresenter
{
    public function __construct(private readonly CatalogCollectionCoverService $covers) {}

    /** @return array<string, mixed> */
    public function directory(bool $localizedAlias = false, bool $statefulVariant = false): array
    {
        $canonical = route('collections.index');

        return [
            'title' => __('collections.seo.directory_title'),
            'description' => __('collections.seo.directory_description'),
            'canonical' => $canonical,
            'robots' => $localizedAlias || $statefulVariant
                ? 'noindex,follow'
                : 'index,follow,max-image-preview:large,max-snippet:-1',
            'section' => __('collections.navigation.collections'),
            'breadcrumbs' => [
                ['name' => __('catalog.navigation.home'), 'url' => route('home')],
                ['name' => __('collections.navigation.collections'), 'url' => $canonical],
            ],
            'alternates' => $localizedAlias || $statefulVariant ? [] : null,
            'jsonLd' => [[
                '@context' => 'https://schema.org',
                '@type' => 'CollectionPage',
                'name' => __('collections.seo.directory_title'),
                'description' => __('collections.seo.directory_description'),
                'url' => $canonical,
                'inLanguage' => app()->currentLocale(),
            ]],
        ];
    }

    /** @return array<string, mixed> */
    public function collection(
        CatalogCollection $collection,
        ?User $viewer,
        bool $localizedAlias = false,
        bool $statefulVariant = false,
    ): array {
        $defaultLocale = (string) config('catalog-collections.default_locale', 'ru');
        $locale = app()->currentLocale();
        $editorialLocales = $collection->type === CatalogCollectionType::Editorial
            ? CatalogCollectionTranslation::query()
                ->whereBelongsTo($collection, 'collection')
                ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
                ->orderBy('locale')
                ->pluck('locale')
                ->unique()
                ->values()
                ->all()
            : [];
        $hasEditorialTranslation = in_array($locale, $editorialLocales, true);
        $localizedCanonical = $localizedAlias
            && $locale !== $defaultLocale
            && $hasEditorialTranslation;
        $canonical = $localizedCanonical
            ? route('localized.collections.show', ['locale' => $locale, 'collectionSlug' => $collection->slug])
            : route('collections.show', ['collectionSlug' => $collection->slug]);
        $owner = $collection->relationLoaded('owner') ? $collection->owner : null;
        $count = (int) ($collection->visible_items_count ?? 0);
        $name = (string) $collection->display_name;
        $description = PlainText::clean($collection->display_seo_description ?? $collection->display_description, 180);

        if ($description === '') {
            $description = __('collections.seo.page_description', ['name' => $name, 'count' => $count]);
        }

        if ($owner !== null) {
            $description = trim($description.' '.__('collections.seo.owner_suffix', ['owner' => $owner->name]));
        }

        $ownerPublicId = $owner?->getAttribute('public_id');
        $ownerUrl = is_string($ownerPublicId) && $ownerPublicId !== ''
            ? route('profiles.collections', ['userPublicId' => $ownerPublicId])
            : null;

        $publiclyIndexable = $collection->visibility === CatalogCollectionVisibility::Public
            && $collection->isPubliclyViewable()
            && $count > 0;
        $indexable = $publiclyIndexable
            && ! $statefulVariant
            && (! $localizedAlias || $localizedCanonical);
        $contentLanguage = $collection->type === CatalogCollectionType::Editorial && $hasEditorialTranslation
            ? $locale
            : $collection->content_locale;
        $alternates = [];

        if ($publiclyIndexable && $collection->type === CatalogCollectionType::Editorial && $editorialLocales !== []) {
            foreach ($editorialLocales as $editorialLocale) {
                $alternates[$editorialLocale] = $editorialLocale === $defaultLocale
                    ? route('collections.show', ['collectionSlug' => $collection->slug])
                    : route('localized.collections.show', [
                        'locale' => $editorialLocale,
                        'collectionSlug' => $collection->slug,
                    ]);
            }

            $alternates['x-default'] = route('collections.show', ['collectionSlug' => $collection->slug]);
        }
        $image = $this->covers->url($collection) ?? $collection->getAttribute('fallback_poster_url');
        $jsonLd = [];

        if ($indexable) {
            $jsonLd[] = array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'CollectionPage',
                'name' => $name,
                'description' => $description,
                'url' => $canonical,
                'image' => $image,
                'numberOfItems' => $count,
                'inLanguage' => $contentLanguage,
                'creator' => $owner === null ? null : array_filter([
                    '@type' => 'Person',
                    'name' => $owner->name,
                    'url' => $ownerUrl,
                ], fn (mixed $value): bool => $value !== null && $value !== ''),
            ], fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return [
            'title' => $collection->display_seo_title ?: __('collections.seo.page_title', ['name' => $name]),
            'description' => $description,
            'canonical' => $canonical,
            'robots' => $indexable ? 'index,follow,max-image-preview:large,max-snippet:-1' : 'noindex,nofollow',
            'social' => $collection->visibility !== CatalogCollectionVisibility::Private,
            'type' => 'website',
            'image' => $image,
            'image_alt' => __('collections.accessibility.collection_cover', ['name' => $name]),
            'published_time' => $indexable ? $collection->published_at?->toAtomString() : null,
            'updated_time' => $collection->updated_at?->toAtomString(),
            'section' => __('collections.navigation.collections'),
            'breadcrumbs' => [
                ['name' => __('catalog.navigation.home'), 'url' => route('home')],
                ['name' => __('collections.navigation.collections'), 'url' => route('collections.index')],
                ['name' => $name, 'url' => $canonical],
            ],
            'alternates' => $statefulVariant ? [] : $alternates,
            'jsonLd' => $jsonLd,
        ];
    }

    /** @return array<string, mixed> */
    public function profile(User $owner, bool $localizedAlias = false, bool $statefulVariant = false): array
    {
        $canonical = route('profiles.collections', ['userPublicId' => $owner->public_id]);

        return [
            'title' => __('collections.profile.title', ['name' => $owner->name]),
            'description' => __('collections.profile.description', ['name' => $owner->name]),
            'canonical' => $canonical,
            'robots' => $localizedAlias || $statefulVariant ? 'noindex,follow' : 'index,follow',
            'breadcrumbs' => [
                ['name' => __('catalog.navigation.home'), 'url' => route('home')],
                ['name' => __('collections.navigation.collections'), 'url' => route('collections.index')],
                ['name' => $owner->name, 'url' => $canonical],
            ],
            'alternates' => [],
        ];
    }
}
