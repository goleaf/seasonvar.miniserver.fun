<?php

namespace App\Services\Catalog\Search;

use Illuminate\Support\Str;
use Normalizer;

final class CatalogSearchNormalizer
{
    private const CYRILLIC_TO_LATIN = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y',
        'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
        'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh',
        'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e',
        'ю' => 'yu', 'я' => 'ya',
    ];

    public function display(string $value): string
    {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);

        return $normalized === false ? '' : Str::squish($normalized);
    }

    public function key(string $value): string
    {
        $normalized = str_replace('ё', 'е', Str::lower($this->display($value)));
        $normalized = preg_replace('/[^\pL\pN]+/u', ' ', $normalized);

        return Str::squish($normalized ?? '');
    }

    /**
     * @return list<string>
     */
    public function tokens(string $value): array
    {
        $normalized = $this->key($value);

        if ($normalized === '') {
            return [];
        }

        return preg_split('/[^\pL\pN]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public function transliterate(string $value): string
    {
        return strtr($this->key($value), self::CYRILLIC_TO_LATIN);
    }

    /**
     * @return list<string>
     */
    public function legacyVariants(string $value): array
    {
        $display = $this->display($value);

        if ($display === '') {
            return [];
        }

        $caseVariants = collect([
            $display,
            Str::lower($display),
            Str::title($display),
            Str::upper($display),
        ]);
        $transliteration = $this->transliterate($display);

        return $caseVariants
            ->flatMap(fn (string $variant): array => [
                $variant,
                str_replace(['ё', 'Ё'], ['е', 'Е'], $variant),
                str_replace(['е', 'Е'], ['ё', 'Ё'], $variant),
            ])
            ->push($transliteration)
            ->push(str_replace('kh', 'x', $transliteration))
            ->map(fn (string $variant): string => trim($variant))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
