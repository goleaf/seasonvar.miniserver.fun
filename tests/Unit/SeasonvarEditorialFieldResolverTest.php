<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CatalogTitle;
use App\Services\Seasonvar\SeasonvarEditorialFieldResolver;
use Tests\TestCase;

class SeasonvarEditorialFieldResolverTest extends TestCase
{
    public function test_legacy_serial_default_is_reclassified_and_existing_provider_baselines_are_preserved(): void
    {
        $title = new CatalogTitle([
            'type' => 'serial',
            'provider_field_values' => ['title' => 'Название провайдера'],
        ]);
        $title->exists = true;

        $resolved = app(SeasonvarEditorialFieldResolver::class)->resolveType($title, 'show');

        $this->assertSame('show', $resolved['value']);
        $this->assertSame([
            'title' => 'Название провайдера',
            'type' => 'show',
        ], $resolved['provider_field_values']);
    }

    public function test_provider_type_changes_only_while_the_current_value_matches_its_baseline(): void
    {
        $providerOwned = new CatalogTitle([
            'type' => 'show',
            'provider_field_values' => ['type' => 'show'],
        ]);
        $providerOwned->exists = true;

        $editoriallyChanged = new CatalogTitle([
            'type' => 'serial',
            'provider_field_values' => ['type' => 'show'],
        ]);
        $editoriallyChanged->exists = true;

        $resolver = app(SeasonvarEditorialFieldResolver::class);

        $this->assertSame('documentary', $resolver->resolveType($providerOwned, 'documentary')['value']);
        $this->assertSame('serial', $resolver->resolveType($editoriallyChanged, 'documentary')['value']);
    }
}
