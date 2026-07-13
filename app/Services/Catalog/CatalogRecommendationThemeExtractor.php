<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Support\Str;

final class CatalogRecommendationThemeExtractor
{
    private const MAX_THEMES = 8;

    /**
     * @var array<string, array{label: string, pattern: string}>
     */
    private const THEMES = [
        'romance' => [
            'label' => 'Романтика',
            'pattern' => '/(?:любов\p{L}*|влюб\p{L}*|романтическ\p{L}*|чувств\p{L}*|свидан\p{L}*)/u',
        ],
        'relationships' => [
            'label' => 'Отношения',
            'pattern' => '/(?:отношен\p{L}*|супруг\p{L}*|жених\p{L}*|невест\p{L}*|семейной пары|молодая пара|между ними)/u',
        ],
        'friendship' => [
            'label' => 'Дружба',
            'pattern' => '/(?:дружб\p{L}*|друзья|друзей|друг с другом|близкими друзьями)/u',
        ],
        'youth' => [
            'label' => 'Молодые герои',
            'pattern' => '/(?:молод\p{L}*|подрост\p{L}*|юнош\p{L}*|юных лет)/u',
        ],
        'family' => [
            'label' => 'Семья',
            'pattern' => '/(?:семь\p{L}*|родител\p{L}*|ребен\p{L}*|детей|сынов\p{L}*|дочер\p{L}*)/u',
        ],
        'workplace' => [
            'label' => 'Работа',
            'pattern' => '/(?:работ\p{L}*|офис\p{L}*|карьер\p{L}*|бизнес\p{L}*)/u',
        ],
        'school' => [
            'label' => 'Учёба',
            'pattern' => '/(?:школ\p{L}*|лице\p{L}*|университет\p{L}*|студент\p{L}*)/u',
        ],
        'medical' => [
            'label' => 'Медицина',
            'pattern' => '/(?:врач\p{L}*|больниц\p{L}*|медицин\p{L}*|пациент\p{L}*)/u',
        ],
        'legal' => [
            'label' => 'Право',
            'pattern' => '/(?:адвокат\p{L}*|юрист\p{L}*|судебн\p{L}*|прокурор\p{L}*)/u',
        ],
        'crime' => [
            'label' => 'Преступление',
            'pattern' => '/(?:преступ\p{L}*|убий\p{L}*|расслед\p{L}*|детектив\p{L}*|криминал\p{L}*)/u',
        ],
        'mystery' => [
            'label' => 'Тайна',
            'pattern' => '/(?:тайн\p{L}*|загад\p{L}*|мистическ\p{L}*)/u',
        ],
        'fantasy' => [
            'label' => 'Фэнтези',
            'pattern' => '/(?:маг\p{L}*|волшеб\p{L}*|фэнтези|сказочн\p{L}*)/u',
        ],
        'supernatural' => [
            'label' => 'Сверхъестественное',
            'pattern' => '/(?:вампир\p{L}*|оборот\p{L}*|призрак\p{L}*|сверхъестествен\p{L}*)/u',
        ],
        'science_fiction' => [
            'label' => 'Фантастика',
            'pattern' => '/(?:космическ\p{L}*|инопланет\p{L}*|робот\p{L}*|будущ\p{L}*|научн\p{L}* фантаст)/u',
        ],
        'historical' => [
            'label' => 'История',
            'pattern' => '/(?:историческ\p{L}*|император\p{L}*|королев\p{L}*|древн\p{L}*|средневек\p{L}*)/u',
        ],
        'military' => [
            'label' => 'Военная тема',
            'pattern' => '/(?:военн\p{L}*|войн\p{L}*|солдат\p{L}*|армия|армии|армией)/u',
        ],
        'adventure' => [
            'label' => 'Приключения',
            'pattern' => '/(?:приключ\p{L}*|путешеств\p{L}*|экспедиц\p{L}*)/u',
        ],
        'sports' => [
            'label' => 'Спорт',
            'pattern' => '/(?:спорт\p{L}*|футбол\p{L}*|баскетбол\p{L}*|соревнован\p{L}*)/u',
        ],
        'music' => [
            'label' => 'Музыка',
            'pattern' => '/(?:музык\p{L}*|певец|певица|песн\p{L}*|оркестр\p{L}*)/u',
        ],
        'show_business' => [
            'label' => 'Шоу-бизнес',
            'pattern' => '/(?:актер\p{L}*|актрис\p{L}*|съем\p{L}*|телевиден\p{L}*|шоу-бизнес)/u',
        ],
        'everyday_life' => [
            'label' => 'Повседневная жизнь',
            'pattern' => '/(?:повседнев\p{L}*|обычной жизн\p{L}*|бытов\p{L}*|житейск\p{L}*)/u',
        ],
    ];

    /**
     * @return array<string, string>
     */
    public function extract(?string $title, ?string $originalTitle, ?string $description): array
    {
        $text = Str::of(implode(' ', array_filter([
            $title,
            $originalTitle,
            $description,
        ], fn (?string $value): bool => filled($value))))
            ->lower()
            ->replace('ё', 'е')
            ->squish()
            ->toString();

        if ($text === '') {
            return [];
        }

        $themes = [];

        foreach (self::THEMES as $key => $definition) {
            if (preg_match($definition['pattern'], $text) === 1) {
                $themes[$key] = $definition['label'];
            }
        }

        return array_slice($themes, 0, self::MAX_THEMES, true);
    }

    public function label(string $theme): ?string
    {
        return self::THEMES[$theme]['label'] ?? null;
    }
}
