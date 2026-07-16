<?php

return [
    'navigation' => 'Top 100',
    'eyebrow' => 'Catalog ranking',
    'all_categories' => 'Ranking categories',
    'method_title' => 'How the list is built',
    'method_description' => 'Order considers both rating and vote count. Kinopoisk is preferred, with IMDb used when no Kinopoisk rating is available.',
    'method_public' => 'Public titles with playable video only',
    'method_limit' => 'Up to 100 positions without artificial fillers',
    'method_updates' => 'Recalculated from current catalog data',
    'leaders' => 'Top three',
    'remaining' => 'Positions 4 to 100',
    'count' => '{0} No positions|{1} :count position|[2,*] :count positions',
    'rating' => ':provider :rating',
    'votes' => '{0} No votes|{1} :count vote|[2,*] :count votes',
    'providers' => [
        'kinopoisk' => 'Kinopoisk',
        'imdb' => 'IMDb',
    ],
    'empty_title' => 'No ranking yet',
    'empty_description' => 'There are currently no rated, available titles in this category.',
    'empty_action' => 'Open the full catalog',
    'validation' => [
        'year' => 'Enter a supported year from 1900 through the next calendar year.',
        'country' => 'Select a country from the available list.',
        'range' => 'The first year cannot be later than the last year.',
    ],
    'attributes' => [
        'year_from' => 'first year',
        'year_to' => 'last year',
        'country' => 'country',
    ],
    'categories' => [
        'movies' => [
            'label' => 'Movies',
            'title' => 'Top 100 movies',
            'description' => 'A ranking of available feature-length and single-part works using Kinopoisk and IMDb ratings with vote confidence.',
            'accessibility' => 'Movie ranking ordered from first place',
        ],
        'series' => [
            'label' => 'Series',
            'title' => 'Top 100 series',
            'description' => 'A ranking of available multi-episode titles, documentary projects and shows using viewer ratings.',
            'accessibility' => 'Series ranking ordered from first place',
        ],
        'anime' => [
            'label' => 'Anime',
            'title' => 'Top 100 anime',
            'description' => 'A ranking of available anime using Kinopoisk and IMDb scores, protected from sparse high ratings.',
            'accessibility' => 'Anime ranking ordered from first place',
        ],
        'cartoons' => [
            'label' => 'Animation',
            'title' => 'Top 100 cartoons',
            'description' => 'A ranking of available animated films and series; anime has its own separate category.',
            'accessibility' => 'Animation ranking ordered from first place',
        ],
    ],
];
