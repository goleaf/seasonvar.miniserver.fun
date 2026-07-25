<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Support\DeterministicUuid;
use Illuminate\Support\Facades\DB;

final class CatalogCollectionCategoryDefaults
{
    private const UUID_NAMESPACE = 'seasonvar.catalog-collection-category';

    /**
     * @return list<array{
     *     slug: string,
     *     ru: string,
     *     en: string,
     *     children: list<array{slug: string, ru: string, en: string}>
     * }>
     */
    public function definitions(): array
    {
        return [
            [
                'slug' => 'themes-and-genres',
                'ru' => 'Темы и жанры',
                'en' => 'Themes and genres',
                'children' => [
                    ['slug' => 'detective-and-crime', 'ru' => 'Детективы и криминал', 'en' => 'Detective and crime'],
                    ['slug' => 'science-fiction-and-fantasy', 'ru' => 'Фантастика и фэнтези', 'en' => 'Science fiction and fantasy'],
                    ['slug' => 'documentary-stories', 'ru' => 'Документальные истории', 'en' => 'Documentary stories'],
                    ['slug' => 'animation-and-anime', 'ru' => 'Анимация и аниме', 'en' => 'Animation and anime'],
                    ['slug' => 'history', 'ru' => 'История', 'en' => 'History'],
                    ['slug' => 'family-and-relationships', 'ru' => 'Семья и отношения', 'en' => 'Family and relationships'],
                    ['slug' => 'comedy', 'ru' => 'Юмор', 'en' => 'Comedy'],
                    ['slug' => 'music', 'ru' => 'Музыка', 'en' => 'Music'],
                ],
            ],
            [
                'slug' => 'mood-and-occasion',
                'ru' => 'Настроение и повод',
                'en' => 'Mood and occasion',
                'children' => [
                    ['slug' => 'weekend', 'ru' => 'На выходные', 'en' => 'For the weekend'],
                    ['slug' => 'calm-evening', 'ru' => 'Для спокойного вечера', 'en' => 'For a quiet evening'],
                    ['slug' => 'for-discussion', 'ru' => 'Для обсуждения', 'en' => 'For discussion'],
                    ['slug' => 'tense-stories', 'ru' => 'Напряжённые истории', 'en' => 'Tense stories'],
                    ['slug' => 'inspiring-stories', 'ru' => 'Вдохновляющие истории', 'en' => 'Inspiring stories'],
                ],
            ],
            [
                'slug' => 'format',
                'ru' => 'Формат',
                'en' => 'Format',
                'children' => [
                    ['slug' => 'mini-series', 'ru' => 'Мини-сериалы', 'en' => 'Miniseries'],
                    ['slug' => 'short-series', 'ru' => 'Короткие сериалы', 'en' => 'Short series'],
                    ['slug' => 'long-stories', 'ru' => 'Долгие истории', 'en' => 'Long-running stories'],
                    ['slug' => 'adaptations', 'ru' => 'Экранизации', 'en' => 'Adaptations'],
                    ['slug' => 'new-and-premieres', 'ru' => 'Новинки и премьеры', 'en' => 'New releases and premieres'],
                ],
            ],
            [
                'slug' => 'countries-and-regions',
                'ru' => 'Страны и регионы',
                'en' => 'Countries and regions',
                'children' => [
                    ['slug' => 'russia', 'ru' => 'Россия', 'en' => 'Russia'],
                    ['slug' => 'united-states', 'ru' => 'США', 'en' => 'United States'],
                    ['slug' => 'europe', 'ru' => 'Европа', 'en' => 'Europe'],
                    ['slug' => 'south-korea', 'ru' => 'Южная Корея', 'en' => 'South Korea'],
                    ['slug' => 'china', 'ru' => 'Китай', 'en' => 'China'],
                    ['slug' => 'turkey', 'ru' => 'Турция', 'en' => 'Turkey'],
                    ['slug' => 'other-countries', 'ru' => 'Другие страны', 'en' => 'Other countries'],
                ],
            ],
            [
                'slug' => 'platforms-and-studios',
                'ru' => 'Платформы и студии',
                'en' => 'Platforms and studios',
                'children' => [
                    ['slug' => 'netflix', 'ru' => 'Netflix', 'en' => 'Netflix'],
                    ['slug' => 'hbo-and-max', 'ru' => 'HBO и Max', 'en' => 'HBO and Max'],
                    ['slug' => 'apple-tv-plus', 'ru' => 'Apple TV+', 'en' => 'Apple TV+'],
                    ['slug' => 'amazon', 'ru' => 'Amazon', 'en' => 'Amazon'],
                    ['slug' => 'disney-plus', 'ru' => 'Disney+', 'en' => 'Disney+'],
                    ['slug' => 'other-platforms', 'ru' => 'Другие платформы', 'en' => 'Other platforms'],
                ],
            ],
        ];
    }

    public function install(): void
    {
        $now = now();

        DB::transaction(function () use ($now): void {
            $definitions = $this->definitions();

            foreach ($definitions as $position => $root) {
                DB::table('catalog_collection_categories')->insertOrIgnore([
                    'public_id' => DeterministicUuid::from(self::UUID_NAMESPACE, $root['slug']),
                    'parent_id' => null,
                    'slug' => $root['slug'],
                    'position' => $position,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $rootIds = DB::table('catalog_collection_categories')
                ->whereIn('slug', array_column($definitions, 'slug'))
                ->pluck('id', 'slug');

            foreach ($definitions as $root) {
                $parentId = $rootIds->get($root['slug']);

                foreach ($root['children'] as $position => $child) {
                    DB::table('catalog_collection_categories')->insertOrIgnore([
                        'public_id' => DeterministicUuid::from(self::UUID_NAMESPACE, $child['slug']),
                        'parent_id' => $parentId,
                        'slug' => $child['slug'],
                        'position' => $position,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            $translations = [];
            $categoryIds = DB::table('catalog_collection_categories')
                ->whereIn('slug', $this->slugs($definitions))
                ->pluck('id', 'slug');

            foreach ($definitions as $root) {
                $translations = [
                    ...$translations,
                    ...$this->translationRows(
                        (int) $categoryIds->get($root['slug']),
                        $root['ru'],
                        $root['en'],
                        $now,
                    ),
                ];

                foreach ($root['children'] as $child) {
                    $translations = [
                        ...$translations,
                        ...$this->translationRows(
                            (int) $categoryIds->get($child['slug']),
                            $child['ru'],
                            $child['en'],
                            $now,
                        ),
                    ];
                }
            }

            DB::table('catalog_collection_category_translations')->insertOrIgnore($translations);
        });
    }

    /**
     * @param  list<array{slug: string, children: list<array{slug: string}>}>  $definitions
     * @return list<string>
     */
    private function slugs(array $definitions): array
    {
        $slugs = [];

        foreach ($definitions as $root) {
            $slugs[] = $root['slug'];

            foreach ($root['children'] as $child) {
                $slugs[] = $child['slug'];
            }
        }

        return $slugs;
    }

    /**
     * @return list<array{
     *     catalog_collection_category_id: int,
     *     locale: string,
     *     name: string,
     *     created_at: mixed,
     *     updated_at: mixed
     * }>
     */
    private function translationRows(int $categoryId, string $russian, string $english, mixed $now): array
    {
        return [
            [
                'catalog_collection_category_id' => $categoryId,
                'locale' => 'ru',
                'name' => $russian,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'catalog_collection_category_id' => $categoryId,
                'locale' => 'en',
                'name' => $english,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];
    }
}
