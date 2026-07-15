<?php

declare(strict_types=1);

namespace App\Services\Tags;

use App\DTOs\TagPageData;
use Illuminate\Support\Str;

final class TagSeoPresenter
{
    /**
     * @param  array<string, mixed>  $seo
     * @return array<string, mixed>
     */
    public function present(array $seo, TagPageData $tag, int $currentPage): array
    {
        $title = $tag->seoTitle ?? __('tags.seo.title', ['tag' => $tag->name]);

        if ($currentPage > 1) {
            $title .= ' — '.__('tags.seo.page', ['page' => $currentPage]);
        }

        $description = $tag->seoDescription
            ?? $tag->shortDescription
            ?? __('tags.seo.description', [
                'tag' => $tag->name,
                'count' => $tag->publicTitleCount,
            ]);
        $canonical = route('titles.taxonomy', ['type' => 'tag', 'taxonomy' => $tag->slug]);
        $breadcrumbs = [
            ['name' => __('catalog.navigation.home'), 'url' => route('home')],
            ['name' => __('catalog.navigation.all_titles'), 'url' => route('titles.index')],
            ['name' => $tag->name, 'url' => $canonical],
        ];
        $pageCanonical = $currentPage > 1 ? $canonical.'?page='.$currentPage : $canonical;

        return [
            ...$seo,
            'title' => $title,
            'h1' => __('tags.page.heading', ['tag' => $tag->name]),
            'lead' => $tag->shortDescription ?? __('tags.page.lead', [
                'tag' => $tag->name,
                'count' => $tag->publicTitleCount,
            ]),
            'description' => Str::limit($description, 320, ''),
            'canonical' => $pageCanonical,
            'section' => __('tags.seo.section'),
            'tags' => collect([$tag->name, ...($seo['tags'] ?? [])])->filter()->unique()->take(20)->all(),
            'breadcrumbs' => $breadcrumbs,
            'jsonLd' => $this->localizedJsonLd(
                (array) ($seo['jsonLd'] ?? []),
                $title,
                Str::limit($description, 320, ''),
                $pageCanonical,
                $breadcrumbs,
                $tag->name,
            ),
            'related_links' => collect($tag->related)
                ->map(fn (array $related): array => [
                    'name' => $related['name'],
                    'url' => route('titles.taxonomy', ['type' => 'tag', 'taxonomy' => $related['slug']]),
                ])
                ->all(),
            'alternates' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $schemas
     * @param  list<array{name: string, url: string}>  $breadcrumbs
     * @return list<array<string, mixed>>
     */
    private function localizedJsonLd(
        array $schemas,
        string $title,
        string $description,
        string $canonical,
        array $breadcrumbs,
        string $tagName,
    ): array {
        return collect($schemas)
            ->map(function (array $schema) use ($title, $description, $canonical, $breadcrumbs, $tagName): array {
                $type = $schema['@type'] ?? null;

                if (in_array($type, ['WebPage', 'CollectionPage'], true)) {
                    $schema['name'] = $title;
                    $schema['description'] = $description;
                    $schema['url'] = $canonical;

                    if (array_key_exists('mainEntity', $schema)) {
                        $schema['mainEntity'] = $canonical;
                    }

                    $schema['about'] = [['@type' => 'Thing', 'name' => $tagName]];
                }

                if ($type === 'ItemList'
                    && data_get($schema, 'itemListElement.0.@type') === 'ListItem') {
                    $schema['name'] = $title;
                }

                if ($type === 'BreadcrumbList') {
                    $schema['itemListElement'] = collect($breadcrumbs)
                        ->values()
                        ->map(fn (array $item, int $index): array => [
                            '@type' => 'ListItem',
                            'position' => $index + 1,
                            'name' => $item['name'],
                            'item' => $item['url'],
                        ])
                        ->all();
                }

                return $schema;
            })
            ->values()
            ->all();
    }
}
