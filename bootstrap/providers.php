<?php

use App\Providers\ApiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\SeasonvarQueueServiceProvider;

return [
    AppServiceProvider::class,
    ApiServiceProvider::class,
    SeasonvarQueueServiceProvider::class,
];
