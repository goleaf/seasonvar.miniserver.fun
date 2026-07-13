<?php

declare(strict_types=1);

namespace App\Support\Cache;

use InvalidArgumentException;

final class CacheTtlPolicy
{
    public function for(CacheDomain $domain): CacheWindow
    {
        $policy = config('cache-architecture.domains.'.$domain->value);

        if (! is_array($policy)) {
            throw new InvalidArgumentException("TTL-политика домена {$domain->value} не настроена.");
        }

        return new CacheWindow(
            freshSeconds: (int) ($policy['fresh'] ?? 0),
            staleSeconds: (int) ($policy['stale'] ?? 0),
            hotSeconds: (int) ($policy['hot'] ?? 0),
            negativeSeconds: (int) ($policy['negative'] ?? 0),
            lockSeconds: (int) ($policy['lock'] ?? 0),
            waitMilliseconds: (int) ($policy['wait_milliseconds'] ?? 0),
            jitterPercent: (int) ($policy['jitter_percent'] ?? 0),
        );
    }
}
