<?php

declare(strict_types=1);

namespace App\Services\HelpCenter;

use App\DTOs\Help\HelpArticleSummaryData;
use App\Enums\HelpFeature;

final readonly class HelpContextualLinkService
{
    public function __construct(
        private HelpCenterSchema $schema,
        private HelpCenterQuery $query,
        private HelpSnapshotCache $cache,
    ) {}

    public function primary(
        HelpFeature $feature,
        string $context,
        string $locale,
        ?string $routeLocale = null,
    ): ?HelpArticleSummaryData {
        if (! $this->schema->ready() || preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/D', $context) !== 1) {
            return null;
        }

        $articles = $this->cache->rememberList('contextual', [
            'feature' => $feature->value,
            'context' => $context,
            'locale' => $locale,
            'route_locale' => $routeLocale,
            'audience' => 'public',
        ], fn (): array => $this->query->contextual(
            $feature->value,
            $context,
            $locale,
            $routeLocale,
            null,
        ),
            static fn (HelpArticleSummaryData $article): array => $article->toCacheSnapshot(),
            static fn (array $snapshot): HelpArticleSummaryData => HelpArticleSummaryData::fromCacheSnapshot($snapshot),
        );

        return $articles[0] ?? null;
    }
}
