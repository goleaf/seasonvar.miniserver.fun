<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Quick start',
    'title' => 'Set up your recommendations',
    'intro' => 'Tell us what you like so your personal selection is useful before watch history has accumulated.',
    'progress' => 'Familiar series selected: :count of 5–10',
    'sections' => [
        'liked' => 'Familiar series you enjoyed',
        'selected_liked' => 'Selected familiar series',
        'search_results' => 'Search results',
        'genres' => 'Favourite genres',
        'countries' => 'Countries of interest',
        'preferences' => 'How you prefer to watch',
        'excluded' => 'Never recommend',
    ],
    'fields' => [
        'liked_titles' => 'Familiar series',
        'excluded_titles' => 'Excluded series',
        'genres' => 'Genres',
        'countries' => 'Countries',
        'locale' => 'Interface language',
        'playback' => 'Dubbing or subtitles',
        'completion' => 'Series status',
        'episode_length' => 'Episode length',
    ],
    'hints' => [
        'liked' => 'Find 5 to 10 series you know and enjoyed.',
        'genres' => 'Choose 1 to 8 genres.',
        'countries' => 'Choose 1 to 8 countries.',
        'locale' => 'This controls the site interface. Audio language depends on available translations.',
        'excluded' => 'You may select up to 10 specific series. They will not appear in recommendations.',
    ],
    'actions' => [
        'search_titles' => 'Search the catalog',
        'remove_title' => 'Remove “:title”',
        'save' => 'Save and open recommendations',
        'saving' => 'Saving…',
        'later' => 'Set up later',
    ],
    'placeholders' => [
        'search' => 'Series title',
    ],
    'states' => [
        'searching' => 'Searching the catalog…',
        'no_results' => 'Nothing matched this search.',
        'no_liked_titles' => 'Nothing selected yet. Add at least five series.',
        'no_excluded_titles' => 'No exclusions selected — this step is optional.',
    ],
    'options' => [
        'locale' => [
            'ru' => 'Русский',
            'en' => 'English',
        ],
        'playback' => [
            'any' => 'No preference',
            'dubbed' => 'Prefer dubbing',
            'subtitles' => 'Prefer subtitles',
        ],
        'completion' => [
            'any' => 'No preference',
            'completed' => 'Completed series',
            'ongoing' => 'Ongoing series',
        ],
        'episode_length' => [
            'any' => 'No preference',
            'short' => 'Short episodes',
            'long' => 'Long episodes',
        ],
    ],
    'validation' => [
        'likedTitleIds' => 'Choose 5 to 10 different familiar series.',
        'excludedTitleIds' => 'You may exclude no more than 10 different series.',
        'genreIds' => 'Choose 1 to 8 different genres.',
        'countryIds' => 'Choose 1 to 8 different countries.',
        'title_overlap' => 'The same series cannot be both liked and excluded.',
        'titles_unavailable' => 'One of the selected series is unavailable. Update your selection.',
        'taxonomy_unavailable' => 'One of the selected options is no longer available. Update your selection.',
        'locale' => 'Choose a supported interface language.',
        'rate_limited' => 'Too many save attempts. Wait one minute.',
    ],
    'errors' => [
        'unavailable' => 'Recommendation setup is temporarily unavailable.',
        'save_failed' => 'The settings could not be saved. Try again.',
    ],
];
