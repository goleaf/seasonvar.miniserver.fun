<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogRelationSourceIdentity;
use App\Models\Source;
use App\Services\Catalog\CatalogRelationSourceIdentityRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogRelationSourceIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_identity_keeps_the_first_canonical_key_without_storing_the_raw_key(): void
    {
        $this->assertTrue(
            class_exists(CatalogRelationSourceIdentityRegistry::class),
            'Catalog relation source identity registry must exist.',
        );

        $source = Source::factory()->create();
        $registry = app(CatalogRelationSourceIdentityRegistry::class);

        $first = $registry->resolve($source->id, 'actor', 'person-42', null, 'john-smith');
        $second = $registry->resolve($source->id, 'actor', 'person-42', null, 'johnathan-smith');

        $this->assertSame('john-smith', $first);
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('catalog_relation_source_identities', 1);

        $identity = CatalogRelationSourceIdentity::query()->sole();

        $this->assertSame(64, strlen($identity->source_key_hash));
        $this->assertStringNotContainsString(
            'person-42',
            json_encode($identity->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_url_identity_normalizes_host_default_port_and_fragment(): void
    {
        $this->assertTrue(
            class_exists(CatalogRelationSourceIdentityRegistry::class),
            'Catalog relation source identity registry must exist.',
        );

        $registry = app(CatalogRelationSourceIdentityRegistry::class);

        $first = $registry->sourceKeyHash(
            null,
            'https://Metadata.Example:443/people/42#filmography',
        );
        $second = $registry->sourceKeyHash(
            null,
            'https://metadata.example/people/42',
        );

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
    }

    public function test_unsupported_type_and_invalid_source_key_do_not_create_identity_rows(): void
    {
        $this->assertTrue(
            class_exists(CatalogRelationSourceIdentityRegistry::class),
            'Catalog relation source identity registry must exist.',
        );

        $source = Source::factory()->create();
        $registry = app(CatalogRelationSourceIdentityRegistry::class);

        $this->assertSame(
            'fallback-key',
            $registry->resolve($source->id, 'unknown', 'provider-1', null, 'fallback-key'),
        );
        $this->assertSame(
            'fallback-key',
            $registry->resolve($source->id, 'actor', "provider\0key", null, 'fallback-key'),
        );
        $this->assertSame(
            'fallback-key',
            $registry->resolve($source->id, 'actor', null, 'http://metadata.example/people/1', 'fallback-key'),
        );

        $this->assertDatabaseCount('catalog_relation_source_identities', 0);
    }
}
