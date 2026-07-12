<?php

namespace App\Services\Catalog\Search;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class CatalogSearchQueryParser
{
    private const STOP_WORDS = [
        'a', 'an', 'and', 'by', 'for', 'from', 'in', 'of', 'on', 'or', 'the', 'to', 'with',
        'актеры', 'альтернативы', 'без', 'в', 'веб', 'все', 'выхода', 'где', 'год', 'года',
        'дат', 'дата', 'для', 'жанр', 'жанры', 'и', 'какая', 'какие', 'календарь',
        'каталог', 'когда', 'качество', 'качестве', 'лучшие', 'мобильный', 'на', 'новая',
        'новые', 'онлайн', 'описание', 'плеер', 'по',
        'подборка', 'подряд', 'после', 'последняя', 'похожие', 'про', 'расписание', 'роли',
        'русском', 'с', 'сезон', 'сезона', 'сезоны', 'серии', 'серий', 'сериал',
        'сериала', 'сериалы', 'сколько', 'смотреть', 'страна', 'страны', 'тема',
        'темы', 'телефоне', 'хорошем', 'что',
    ];

    public function __construct(private readonly CatalogSearchNormalizer $normalizer) {}

    public function isStopWord(string $term): bool
    {
        return in_array($term, self::STOP_WORDS, true);
    }

    public function parse(string $value): CatalogSearchQuery
    {
        $raw = $this->normalizer->display($value);
        $tokens = collect($this->normalizer->tokens($raw));
        $years = $tokens
            ->filter(fn (string $term): bool => preg_match('/^\d{4}$/', $term) === 1)
            ->map(fn (string $term): int => (int) $term)
            ->filter(fn (int $year): bool => $year >= 1900 && $year <= ((int) now()->format('Y') + 1))
            ->unique()
            ->values();
        $year = $years->count() === 1 ? $years->first() : null;
        $terms = $tokens
            ->reject(fn (string $term): bool => $year !== null && $term === (string) $year)
            ->reject(fn (string $term): bool => $this->isStopWord($term))
            ->filter(fn (string $term): bool => preg_match('/^\d+$/', $term) === 1 || mb_strlen($term) >= 2)
            ->unique()
            ->take(8)
            ->values();
        $state = match (true) {
            $raw === '' => CatalogSearchState::Empty,
            $terms->isEmpty() && $year === null => CatalogSearchState::Insufficient,
            default => CatalogSearchState::Ready,
        };

        return new CatalogSearchQuery(
            raw: $raw,
            normalized: $this->normalizer->key($raw),
            terms: $terms->all(),
            year: $year,
            state: $state,
            ftsExpression: $this->ftsExpression($terms),
            exactNameHashes: $this->exactNameHashes($terms),
        );
    }

    /**
     * @param  Collection<int, string>  $terms
     */
    private function ftsExpression(Collection $terms): string
    {
        return $terms
            ->map(function (string $term): string {
                $usesPrefix = preg_match('/^\d+$/', $term) !== 1 && mb_strlen($term) >= 3;

                return '"'.$term.'"'.($usesPrefix ? '*' : '');
            })
            ->implode(' AND ');
    }

    /**
     * @param  Collection<int, string>  $terms
     * @return list<string>
     */
    private function exactNameHashes(Collection $terms): array
    {
        return collect($this->normalizer->legacyVariants($terms->implode(' ')))
            ->map(fn (string $variant): string => hash('sha256', Str::lower(Str::squish($variant))))
            ->unique()
            ->values()
            ->all();
    }
}
