<?php

declare(strict_types=1);

return [
    'registration' => [
        'enabled' => (bool) env('AUTH_REGISTRATION_ENABLED', true),
    ],
];
