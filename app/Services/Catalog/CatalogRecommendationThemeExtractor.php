<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Support\Str;

final class CatalogRecommendationThemeExtractor
{
    private const MAX_THEMES = 8;

    /** @var array<string, array{label: string, terms: list<string>, prefixes: list<string>, phrases: list<string>}> */
    private const THEMES = [
        'romance' => [
            'label' => 'Романтика',
            'terms' => ['любовь', 'любви', 'любовью'],
            'prefixes' => ['влюб', 'романтическ', 'свидан'],
            'phrases' => ['любовная история'],
        ],
        'relationships' => [
            'label' => 'Отношения',
            'terms' => [],
            'prefixes' => ['отношен', 'супруг', 'жених', 'невест'],
            'phrases' => ['семейная пара', 'семейной пары', 'молодая пара', 'между ними появляются чувства'],
        ],
        'friendship' => [
            'label' => 'Дружба',
            'terms' => ['друг', 'друга', 'другом', 'друзья', 'друзей', 'друзьями'],
            'prefixes' => ['друж'],
            'phrases' => ['друг с другом', 'близкими друзьями'],
        ],
        'youth' => [
            'label' => 'Молодые герои',
            'terms' => [],
            'prefixes' => ['молод', 'подрост', 'юнош'],
            'phrases' => ['юных лет'],
        ],
        'family' => [
            'label' => 'Семья',
            'terms' => ['семья', 'семьи', 'семье', 'семью', 'семьей', 'дети', 'детей', 'детьми', 'сын', 'сына', 'сыновья', 'дочь', 'дочери'],
            'prefixes' => ['семейн', 'родител', 'ребен', 'детск', 'сынов', 'дочер'],
            'phrases' => [],
        ],
        'workplace' => [
            'label' => 'Работа',
            'terms' => [],
            'prefixes' => ['работ', 'офис', 'карьер', 'бизнес'],
            'phrases' => [],
        ],
        'school' => [
            'label' => 'Учёба',
            'terms' => ['лицей', 'лицея', 'лицее', 'лицеем', 'лицеи'],
            'prefixes' => ['школ', 'университет', 'студент'],
            'phrases' => [],
        ],
        'medical' => [
            'label' => 'Медицина',
            'terms' => [],
            'prefixes' => ['врач', 'больниц', 'медицин', 'пациент'],
            'phrases' => [],
        ],
        'legal' => [
            'label' => 'Право',
            'terms' => [],
            'prefixes' => ['адвокат', 'юрист', 'судебн', 'прокурор'],
            'phrases' => [],
        ],
        'crime' => [
            'label' => 'Преступление',
            'terms' => [],
            'prefixes' => ['преступ', 'убий', 'расслед', 'детектив', 'криминал'],
            'phrases' => [],
        ],
        'mystery' => [
            'label' => 'Тайна',
            'terms' => [],
            'prefixes' => ['тайн', 'загад', 'мистическ'],
            'phrases' => [],
        ],
        'fantasy' => [
            'label' => 'Фэнтези',
            'terms' => ['маг', 'маги', 'магия', 'магии', 'магию', 'фэнтези'],
            'prefixes' => ['магическ', 'волшеб', 'сказочн'],
            'phrases' => ['мир фэнтези'],
        ],
        'supernatural' => [
            'label' => 'Сверхъестественное',
            'terms' => [],
            'prefixes' => ['вампир', 'оборот', 'призрак', 'сверхъестествен'],
            'phrases' => [],
        ],
        'science_fiction' => [
            'label' => 'Фантастика',
            'terms' => ['робот', 'роботы', 'роботов'],
            'prefixes' => ['космическ', 'инопланет', 'робот'],
            'phrases' => ['научная фантастика', 'научной фантастики'],
        ],
        'historical' => [
            'label' => 'История',
            'terms' => [],
            'prefixes' => ['историческ', 'император', 'королев', 'древн', 'средневек'],
            'phrases' => [],
        ],
        'military' => [
            'label' => 'Военная тема',
            'terms' => ['война', 'войны', 'войне', 'войну', 'войной', 'армия', 'армии', 'армией'],
            'prefixes' => ['военн', 'солдат'],
            'phrases' => [],
        ],
        'adventure' => [
            'label' => 'Приключения',
            'terms' => [],
            'prefixes' => ['приключ', 'путешеств', 'экспедиц'],
            'phrases' => [],
        ],
        'sports' => [
            'label' => 'Спорт',
            'terms' => ['спорт', 'спорта', 'спорте', 'спортом'],
            'prefixes' => ['спортивн', 'футбол', 'баскетбол', 'соревнован'],
            'phrases' => [],
        ],
        'music' => [
            'label' => 'Музыка',
            'terms' => ['певец', 'певица'],
            'prefixes' => ['музык', 'песн', 'оркестр'],
            'phrases' => [],
        ],
        'show_business' => [
            'label' => 'Шоу-бизнес',
            'terms' => ['актер', 'актеры', 'актера', 'актеров', 'актриса', 'актрисы'],
            'prefixes' => ['актерск', 'актрис', 'съемочн', 'телевиден'],
            'phrases' => ['шоу бизнес'],
        ],
        'everyday_life' => [
            'label' => 'Повседневная жизнь',
            'terms' => [],
            'prefixes' => ['повседнев', 'бытов', 'житейск'],
            'phrases' => ['обычная жизнь', 'обычной жизни'],
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

        $lexicalText = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;
        $lexicalText = Str::squish($lexicalText);
        preg_match_all('/[\p{L}\p{N}]+/u', $lexicalText, $matches);
        $tokens = array_values(array_unique($matches[0] ?? []));
        $tokenLookup = array_fill_keys($tokens, true);
        $themes = [];

        foreach (self::THEMES as $key => $definition) {
            if ($this->matches($definition, $lexicalText, $tokens, $tokenLookup)) {
                $themes[$key] = $definition['label'];
            }
        }

        return array_slice($themes, 0, self::MAX_THEMES, true);
    }

    public function label(string $theme): ?string
    {
        return self::THEMES[$theme]['label'] ?? null;
    }

    /**
     * @param  array{label: string, terms: list<string>, prefixes: list<string>, phrases: list<string>}  $definition
     * @param  list<string>  $tokens
     * @param  array<string, true>  $tokenLookup
     */
    private function matches(array $definition, string $text, array $tokens, array $tokenLookup): bool
    {
        foreach ($definition['terms'] as $term) {
            if (isset($tokenLookup[$term])) {
                return true;
            }
        }

        foreach ($definition['prefixes'] as $prefix) {
            foreach ($tokens as $token) {
                if (str_starts_with($token, $prefix)) {
                    return true;
                }
            }
        }

        $paddedText = ' '.$text.' ';

        foreach ($definition['phrases'] as $phrase) {
            if (str_contains($paddedText, ' '.$phrase.' ')) {
                return true;
            }
        }

        return false;
    }
}
