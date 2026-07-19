<?php

declare(strict_types=1);

namespace App\Support\Cache;

enum CacheEntryState: string
{
    case Fresh = 'fresh';
    case Stale = 'stale';
    case Missing = 'missing';
    case Unavailable = 'unavailable';

    public function needsWarm(): bool
    {
        return $this === self::Missing || $this === self::Stale;
    }
}
