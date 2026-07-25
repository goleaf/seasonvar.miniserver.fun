<?php

declare(strict_types=1);

namespace App\Services\Collections;

final class CatalogCollectionCategorySuggestionRules
{
    /**
     * @return array<string, array{
     *     category_slug: string,
     *     title_terms: list<string>,
     *     description_terms: list<string>,
     *     genres: list<string>,
     *     countries: list<string>,
     *     networks: list<string>,
     *     studios: list<string>,
     *     types: list<string>
     * }>
     */
    public function definitions(): array
    {
        return [
            'detective-and-crime' => $this->rule(
                'detective-and-crime',
                ['детектив', 'детективы', 'криминал', 'crime', 'detective', 'detectives'],
                ['детектив', 'криминал', 'расследован', 'crime', 'detective', 'investigation'],
                ['детектив', 'криминал', 'crime', 'detective'],
            ),
            'science-fiction-and-fantasy' => $this->rule(
                'science-fiction-and-fantasy',
                ['фантастика', 'фантастические', 'фэнтези', 'science fiction', 'sci fi', 'fantasy'],
                ['фантастика', 'фэнтези', 'science fiction', 'sci fi', 'fantasy'],
                ['фантастика', 'фэнтези', 'science-fiction', 'sci-fi', 'fantasy'],
            ),
            'documentary-stories' => $this->rule(
                'documentary-stories',
                ['документальн', 'documentary'],
                ['документальн', 'documentary'],
                ['документальный', 'documentary'],
                types: ['documentary'],
            ),
            'animation-and-anime' => $this->rule(
                'animation-and-anime',
                ['аниме', 'animation', 'animated', 'мультсериал', 'мультфильм'],
                ['аниме', 'animation', 'animated', 'мультсериал', 'мультфильм'],
                ['anime', 'animation', 'аниме', 'мультфильм'],
                types: ['anime', 'animation'],
            ),
            'history' => $this->rule(
                'history',
                ['историческ', 'history', 'historical'],
                ['историческ', 'history', 'historical'],
                ['история', 'history', 'historical'],
            ),
            'family-and-relationships' => $this->rule(
                'family-and-relationships',
                ['семейн', 'отношени', 'family', 'relationship'],
                ['семейн', 'отношени', 'family', 'relationship'],
                ['семейный', 'мелодрама', 'family', 'romance'],
            ),
            'comedy' => $this->rule(
                'comedy',
                ['комеди', 'юмор', 'comedy', 'humor'],
                ['комеди', 'юмор', 'comedy', 'humor'],
                ['комедия', 'comedy'],
            ),
            'music' => $this->rule(
                'music',
                ['музык', 'music'],
                ['музык', 'music'],
                ['музыка', 'music', 'musical'],
            ),
            'mini-series' => $this->rule(
                'mini-series',
                ['мини сериал', 'мини-сериал', 'miniseries', 'mini series'],
                ['мини сериал', 'мини-сериал', 'miniseries', 'mini series'],
            ),
            'short-series' => $this->rule(
                'short-series',
                ['короткие сериалы', 'короткий сериал', 'short series'],
                ['короткие сериалы', 'короткий сериал', 'short series'],
            ),
            'adaptations' => $this->rule(
                'adaptations',
                ['экранизаци', 'по книгам', 'adaptation', 'based on books'],
                ['экранизаци', 'по книге', 'adaptation', 'based on a book'],
            ),
            'new-and-premieres' => $this->rule(
                'new-and-premieres',
                ['новинки', 'премьеры', 'new releases', 'premieres'],
                ['новинки', 'премьеры', 'new releases', 'premieres'],
            ),
            'russia' => $this->rule(
                'russia',
                ['росси', 'russia', 'russian'],
                ['росси', 'russia', 'russian'],
                countries: ['россия', 'russia'],
            ),
            'united-states' => $this->rule(
                'united-states',
                ['сша', 'американск', 'united states', 'american'],
                ['сша', 'американск', 'united states', 'american'],
                countries: ['сша', 'united-states', 'united states', 'usa'],
            ),
            'europe' => $this->rule(
                'europe',
                ['европейск', 'европа', 'european', 'europe'],
                ['европейск', 'европа', 'european', 'europe'],
                countries: [
                    'австрия', 'бельгия', 'великобритания', 'германия', 'дания',
                    'испания', 'италия', 'нидерланды', 'норвегия', 'польша',
                    'португалия', 'финляндия', 'франция', 'швеция', 'europe',
                ],
            ),
            'south-korea' => $this->rule(
                'south-korea',
                ['южная корея', 'корейск', 'south korea', 'korean'],
                ['южная корея', 'корейск', 'south korea', 'korean'],
                countries: ['южная-корея', 'южная корея', 'south-korea', 'south korea'],
            ),
            'china' => $this->rule(
                'china',
                ['китайск', 'китай', 'chinese', 'china'],
                ['китайск', 'китай', 'chinese', 'china'],
                countries: ['китай', 'china'],
            ),
            'turkey' => $this->rule(
                'turkey',
                ['турецк', 'турция', 'turkish', 'turkey'],
                ['турецк', 'турция', 'turkish', 'turkey'],
                countries: ['турция', 'turkey'],
            ),
            'netflix' => $this->rule(
                'netflix',
                ['netflix', 'нетфликс'],
                ['netflix', 'нетфликс'],
                networks: ['netflix'],
                studios: ['netflix'],
            ),
            'hbo-and-max' => $this->rule(
                'hbo-and-max',
                ['hbo', 'max originals', 'max original'],
                ['hbo', 'max originals', 'max original'],
                networks: ['hbo', 'max'],
                studios: ['hbo', 'max'],
            ),
            'apple-tv-plus' => $this->rule(
                'apple-tv-plus',
                ['apple tv', 'apple tv plus'],
                ['apple tv', 'apple tv plus'],
                networks: ['apple-tv-plus', 'apple tv+'],
                studios: ['apple-tv-plus', 'apple tv+'],
            ),
            'amazon' => $this->rule(
                'amazon',
                ['amazon', 'prime video'],
                ['amazon', 'prime video'],
                networks: ['amazon', 'prime-video'],
                studios: ['amazon', 'prime-video'],
            ),
            'disney-plus' => $this->rule(
                'disney-plus',
                ['disney plus', 'disney+'],
                ['disney plus', 'disney+'],
                networks: ['disney-plus', 'disney+'],
                studios: ['disney-plus', 'disney+'],
            ),
        ];
    }

    /**
     * @param  list<string>  $titleTerms
     * @param  list<string>  $descriptionTerms
     * @param  list<string>  $genres
     * @param  list<string>  $countries
     * @param  list<string>  $networks
     * @param  list<string>  $studios
     * @param  list<string>  $types
     * @return array{
     *     category_slug: string,
     *     title_terms: list<string>,
     *     description_terms: list<string>,
     *     genres: list<string>,
     *     countries: list<string>,
     *     networks: list<string>,
     *     studios: list<string>,
     *     types: list<string>
     * }
     */
    private function rule(
        string $slug,
        array $titleTerms,
        array $descriptionTerms,
        array $genres = [],
        array $countries = [],
        array $networks = [],
        array $studios = [],
        array $types = [],
    ): array {
        return [
            'category_slug' => $slug,
            'title_terms' => $titleTerms,
            'description_terms' => $descriptionTerms,
            'genres' => $genres,
            'countries' => $countries,
            'networks' => $networks,
            'studios' => $studios,
            'types' => $types,
        ];
    }
}
