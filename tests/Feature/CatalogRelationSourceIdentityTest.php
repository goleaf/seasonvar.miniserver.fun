<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\CatalogRelationSourceIdentity;
use App\Models\CatalogTitle;
use App\Models\Source;
use App\Services\Catalog\CatalogRelationNameSanitizer;
use App\Services\Catalog\CatalogRelationSourceIdentityRegistry;
use App\Services\Catalog\CatalogRelationSyncer;
use App\Services\Catalog\CatalogTaxonomyRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

    public function test_registry_fails_open_while_the_additive_migration_is_pending(): void
    {
        $source = Source::factory()->create();
        Schema::drop('catalog_relation_source_identities');

        try {
            $registry = app(CatalogRelationSourceIdentityRegistry::class);

            $this->assertSame(
                'john-smith',
                $registry->resolve($source->id, 'actor', 'person-42', null, 'john-smith'),
            );
            $this->assertSame(0, $registry->rebind('actor', ['john-smith'], 'johnathan-smith'));
            $this->assertSame(0, $registry->pruneMissing('actor', 'actors'));
            $this->assertSame(0, $registry->pruneUnsupported());
        } finally {
            Schema::create('catalog_relation_source_identities', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('source_id')->constrained()->cascadeOnDelete();
                $table->string('relation_type', 32);
                $table->string('source_key_hash', 64);
                $table->string('canonical_key');
                $table->timestamps();
                $table->unique(
                    ['source_id', 'relation_type', 'source_key_hash'],
                    'catalog_relation_source_identity_unique',
                );
                $table->index(
                    ['relation_type', 'canonical_key'],
                    'catalog_relation_source_identity_canonical_idx',
                );
            });
        }
    }

    public function test_stable_external_identity_prevents_renamed_duplicates_for_every_relation_type(): void
    {
        $source = Source::factory()->create();
        $title = CatalogTitle::factory()->create(['source_id' => $source->id]);
        $syncer = app(CatalogRelationSyncer::class);
        $taxonomies = app(CatalogTaxonomyRegistry::class);
        $names = app(CatalogRelationNameSanitizer::class);
        $cases = [
            'genre' => ['Драма', 'Мелодрама'],
            'country' => ['Россия', 'Япония'],
            'actor' => ['John Smith', 'Johnathan Smith'],
            'director' => ['Jane Doe', 'Janet Doe'],
            'age_rating' => ['16+', '18+'],
            'translation' => ['LostFilm', 'Кубик в кубе'],
            'status' => ['Выходит', 'Завершён'],
            'network' => ['HBO', 'Netflix'],
            'studio' => ['Warner Bros', 'Paramount'],
            'tag' => ['Семейное', 'Детектив'],
        ];

        foreach ($cases as $type => [$firstName, $renamedValue]) {
            $syncer->sync($title, [[
                'type' => $type,
                'name' => $firstName,
                'source_external_id' => 'stable-'.$type,
            ]]);
            $syncer->sync($title, [[
                'type' => $type,
                'name' => $renamedValue,
                'source_external_id' => 'stable-'.$type,
            ]]);

            $modelClass = $taxonomies->modelClass($type);
            $relation = $taxonomies->relationName($type);

            $this->assertSame(1, $modelClass::query()->count(), $type);
            $this->assertSame(
                $names->canonicalKey($type, $firstName),
                $modelClass::query()->sole()->slug,
                $type,
            );
            $this->assertSame(1, $title->{$relation}()->count(), $type);
        }

        $this->assertDatabaseCount('catalog_relation_source_identities', count($cases));
    }

    public function test_equivalent_names_from_different_sources_share_one_record_and_keep_both_identities(): void
    {
        $firstSource = Source::factory()->create();
        $secondSource = Source::factory()->create();
        $title = CatalogTitle::factory()->create(['source_id' => $firstSource->id]);
        $syncer = app(CatalogRelationSyncer::class);

        $syncer->sync($title, [[
            'type' => 'actor',
            'name' => 'Atsuko Tanaka',
            'source_external_id' => 'person-1',
        ]]);
        $syncer->sync($title, [[
            'type' => 'actor',
            'name' => 'Ацуко Танака',
            'source_id' => $secondSource->id,
            'source_external_id' => 'actor-77',
        ]]);

        $actor = Actor::query()->sole();

        $this->assertSame('atsuko-tanaka', $actor->slug);
        $this->assertSame('Ацуко Танака', $actor->name);
        $this->assertDatabaseCount('catalog_title_actor', 1);
        $this->assertDatabaseCount('catalog_relation_source_identities', 2);
        $this->assertEqualsCanonicalizing(
            [$firstSource->id, $secondSource->id],
            CatalogRelationSourceIdentity::query()->pluck('source_id')->all(),
        );
    }

    public function test_first_refresh_claims_an_existing_provenance_url_before_a_provider_rename(): void
    {
        $source = Source::factory()->create();
        $title = CatalogTitle::factory()->create(['source_id' => $source->id]);
        $actor = Actor::query()->create([
            'name' => 'John Smith',
            'slug' => 'john-smith',
            'source_url' => 'https://metadata.example/people/42',
        ]);

        app(CatalogRelationSyncer::class)->sync($title, [[
            'type' => 'actor',
            'name' => 'Johnathan Smith',
            'source_url' => 'https://metadata.example/people/42',
        ]]);

        $this->assertDatabaseCount('actors', 1);
        $this->assertSame($actor->id, Actor::query()->sole()->id);
        $this->assertSame('john-smith', Actor::query()->sole()->slug);
        $this->assertDatabaseHas('catalog_relation_source_identities', [
            'source_id' => $source->id,
            'relation_type' => 'actor',
            'canonical_key' => 'john-smith',
        ]);
    }

    public function test_relation_sync_rolls_back_lookup_and_identity_when_pivot_write_fails(): void
    {
        $source = Source::factory()->create();
        $title = CatalogTitle::factory()->create(['source_id' => $source->id]);
        $title->forceDelete();

        try {
            app(CatalogRelationSyncer::class)->sync($title, [[
                'type' => 'actor',
                'name' => 'John Smith',
                'source_external_id' => 'person-42',
            ]]);
            $this->fail('Pivot write for a deleted title must fail.');
        } catch (QueryException) {
            $this->assertDatabaseCount('actors', 0);
            $this->assertDatabaseCount('catalog_relation_source_identities', 0);
        }
    }
}
