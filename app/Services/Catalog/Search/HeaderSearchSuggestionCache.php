<?php

declare(strict_types=1);

namespace App\Services\Catalog\Search;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheTtlPolicy;
use App\Support\Cache\TieredCache;
use Closure;

final readonly class HeaderSearchSuggestionCache
{
    private const FORMAT_VERSION = 1;

    public function __construct(
        private TieredCache $cache,
        private CacheTtlPolicy $ttl,
    ) {}

    /**
     * @param  Closure(): array<mixed>  $rebuild
     * @return array<mixed>
     */
    public function remember(string $normalizedQuery, Closure $rebuild, string $scope = 'grouped'): array
    {
        $result = $this->cache->remember(
            CacheDomain::SearchSuggestions,
            'header-autocomplete',
            [
                'format' => self::FORMAT_VERSION,
                'locale' => app()->getLocale(),
                'audience' => 'public',
                'scope' => $scope,
                'query' => hash('sha256', $normalizedQuery),
            ],
            $this->ttl->for(CacheDomain::SearchSuggestions),
            $rebuild,
        );

        return is_array($result->value) ? $result->value : [];
    }
}
