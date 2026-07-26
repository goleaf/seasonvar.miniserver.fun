<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\DTOs\DemoData\DemoDataOptions;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Services\Collections\Import\HdRezkaPublicCollectionCleaner;
use App\Services\DemoData\DemoPublicCollectionCleaner;

final readonly class CatalogCollectionPublicQualityRepairer
{
    public function __construct(
        private DemoPublicCollectionCleaner $demoCollections,
        private HdRezkaPublicCollectionCleaner $sourceCollections,
    ) {}

    /** @return array<string, int> */
    public function inspect(): array
    {
        return [
            'public_approved_records' => CatalogCollection::query()
                ->where('visibility', CatalogCollectionVisibility::Public->value)
                ->where('moderation_status', CatalogCollectionModerationStatus::Approved->value)
                ->count(),
            'publicly_listed_records' => CatalogCollection::query()->publiclyListed()->count(),
            ...$this->demoCollections->inspect(DemoDataOptions::fromConfig()),
            ...$this->sourceCollections->inspect(),
        ];
    }

    /**
     * @return array{
     *     before: array<string, int>,
     *     after: array<string, int>,
     *     counters: array<string, int>
     * }
     */
    public function repair(): array
    {
        $before = $this->inspect();
        $demo = $this->demoCollections->repair(DemoDataOptions::fromConfig());
        $source = $this->sourceCollections->repair();

        return [
            'before' => $before,
            'after' => $this->inspect(),
            'counters' => [
                'demo_collections_quarantined' => $demo['demo_collections_quarantined'],
                'source_collections_quarantined' => $source['source_collections_quarantined'],
                'source_signals_deleted' => $source['source_signals_deleted'],
                'source_recommendations_invalidated' => $source['source_recommendations_invalidated'],
            ],
        ];
    }
}
