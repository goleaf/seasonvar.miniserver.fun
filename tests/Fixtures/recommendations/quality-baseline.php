<?php

declare(strict_types=1);

return [
    'limit' => 2,
    'rows' => [
        ['source' => 'source-a', 'candidate' => 'great', 'rank' => 1, 'watchable' => true, 'reasons' => ['genre']],
        ['source' => 'source-a', 'candidate' => 'bad', 'rank' => 2, 'watchable' => true, 'reasons' => ['actor']],
        ['source' => 'source-b', 'candidate' => 'great', 'rank' => 1, 'watchable' => false, 'reasons' => []],
    ],
    'grades' => [
        'source-a' => ['great' => 2, 'bad' => 0],
        'source-b' => ['great' => 1],
        'source-c' => [],
    ],
];
