<?php

namespace App\Support;

final class CatalogAlphabet
{
    /** @var list<string> */
    private const TITLE_CYRILLIC = ['А', 'Б', 'В', 'Г', 'Д', 'Е', 'Ж', 'З', 'И', 'Й', 'К', 'Л', 'М', 'Н', 'О', 'П', 'Р', 'С', 'Т', 'У', 'Ф', 'Х', 'Ц', 'Ч', 'Ш', 'Щ', 'Ъ', 'Ы', 'Ь', 'Э', 'Ю', 'Я'];

    /** @var list<string> */
    private const CYRILLIC = ['А', 'Б', 'В', 'Г', 'Д', 'Е', 'Ё', 'Ж', 'З', 'И', 'Й', 'К', 'Л', 'М', 'Н', 'О', 'П', 'Р', 'С', 'Т', 'У', 'Ф', 'Х', 'Ц', 'Ч', 'Ш', 'Щ', 'Ъ', 'Ы', 'Ь', 'Э', 'Ю', 'Я'];

    /** @var list<string> */
    private const LATIN = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];

    /** @return array{symbols: list<string>, cyrillic: list<string>, latin: list<string>} */
    public static function titleGroups(): array
    {
        return [
            'symbols' => ['#'],
            'cyrillic' => self::TITLE_CYRILLIC,
            'latin' => self::LATIN,
        ];
    }

    /**
     * @param  iterable<mixed>  $letters
     * @return array{symbols: list<string>, cyrillic: list<string>, latin: list<string>}
     */
    public static function availableGroups(iterable $letters): array
    {
        $available = collect($letters)
            ->filter(fn (mixed $letter): bool => is_string($letter) && $letter !== '')
            ->map(fn (string $letter): string => mb_strtoupper($letter))
            ->unique()
            ->values()
            ->all();

        return [
            'symbols' => in_array('#', $available, true) ? ['#'] : [],
            'cyrillic' => array_values(array_intersect(self::CYRILLIC, $available)),
            'latin' => array_values(array_intersect(self::LATIN, $available)),
        ];
    }
}
