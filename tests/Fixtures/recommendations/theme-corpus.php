<?php

declare(strict_types=1);

return [
    'character-is-not-actor' => [
        'text' => 'У героя сложный характер, но он не работает в кино.',
        'present' => [],
        'missing' => ['show_business'],
    ],
    'shop-is-not-magic' => [
        'text' => 'Она работает в магазине у моря.',
        'present' => ['workplace'],
        'missing' => ['fantasy'],
    ],
    'face-is-not-lyceum' => [
        'text' => 'На его лице появилась улыбка.',
        'present' => [],
        'missing' => ['school'],
    ],
    'double-is-not-war' => [
        'text' => 'Агент ведёт двойную жизнь.',
        'present' => [],
        'missing' => ['military'],
    ],
    'passport-is-not-sport' => [
        'text' => 'Путешественник потерял паспорт в аэропорту.',
        'present' => ['adventure'],
        'missing' => ['sports'],
    ],
    'seven-is-not-family' => [
        'text' => 'Семь участников дошли до финала.',
        'present' => [],
        'missing' => ['family'],
    ],
    'papers-are-not-magic' => [
        'text' => 'Юрист разбирает старые бумаги перед судом.',
        'present' => ['legal'],
        'missing' => ['fantasy'],
    ],
    'real-terms-still-match' => [
        'text' => 'Магия меняет семью молодого актёра, который учится в лицее и занимается спортом после военной службы.',
        'present' => ['fantasy', 'family', 'youth', 'show_business', 'school', 'sports', 'military'],
        'missing' => [],
    ],
];
