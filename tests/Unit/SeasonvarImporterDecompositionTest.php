<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Presenters\SeasonvarProgressPresenter;
use App\Console\Presenters\SeasonvarQueueStatusPresenter;
use App\Console\Presenters\SeasonvarSourceInventoryPresenter;
use App\Services\Catalog\CatalogMetadataDeduplicator;
use App\Services\Catalog\CatalogRelationNameSanitizer;
use App\Services\Seasonvar\SeasonvarCatalogIdentityResolver;
use App\Services\Seasonvar\SeasonvarCatalogImporter;
use App\Services\Seasonvar\SeasonvarCatalogMediaSynchronizer;
use App\Services\Seasonvar\SeasonvarCatalogParser;
use App\Services\Seasonvar\SeasonvarCatalogTitleWriter;
use App\Services\Seasonvar\SeasonvarEditorialFieldResolver;
use App\Services\Seasonvar\SeasonvarEpisodeScriptParser;
use App\Services\Seasonvar\SeasonvarImportMaintenancePipeline;
use App\Services\Seasonvar\SeasonvarImportPipeline;
use App\Services\Seasonvar\SeasonvarImportStorageMaintenance;
use App\Services\Seasonvar\SeasonvarMediaCandidateParser;
use App\Services\Seasonvar\SeasonvarRelationMetadataNormalizer;
use App\Services\Seasonvar\SeasonvarStructuredDataParser;
use App\Services\Seasonvar\SeasonvarTaxonomyParser;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

class SeasonvarImporterDecompositionTest extends TestCase
{
    public function test_catalog_importer_depends_on_cohesive_write_and_media_boundaries(): void
    {
        $dependencies = $this->constructorDependencyNames(SeasonvarCatalogImporter::class);

        $this->assertContains(SeasonvarCatalogTitleWriter::class, $dependencies);
        $this->assertContains(
            SeasonvarCatalogMediaSynchronizer::class,
            $dependencies,
        );
        $this->assertNotContains(SeasonvarCatalogIdentityResolver::class, $dependencies);
        $this->assertNotContains(SeasonvarEditorialFieldResolver::class, $dependencies);
        $this->assertNotContains(SeasonvarRelationMetadataNormalizer::class, $dependencies);
    }

    public function test_import_pipeline_depends_on_one_maintenance_boundary(): void
    {
        $dependencies = $this->constructorDependencyNames(SeasonvarImportPipeline::class);

        $this->assertContains(SeasonvarImportMaintenancePipeline::class, $dependencies);
        $this->assertNotContains(SeasonvarImportStorageMaintenance::class, $dependencies);
        $this->assertNotContains(CatalogMetadataDeduplicator::class, $dependencies);
    }

    public function test_parser_facade_depends_on_four_cohesive_parser_collaborators(): void
    {
        $dependencies = $this->constructorDependencyNames(SeasonvarCatalogParser::class);

        $this->assertContains(SeasonvarStructuredDataParser::class, $dependencies);
        $this->assertContains(SeasonvarEpisodeScriptParser::class, $dependencies);
        $this->assertContains(SeasonvarMediaCandidateParser::class, $dependencies);
        $this->assertContains(SeasonvarTaxonomyParser::class, $dependencies);
        $this->assertNotContains(CatalogRelationNameSanitizer::class, $dependencies);
        $this->assertNotContains(SeasonvarRelationMetadataNormalizer::class, $dependencies);
    }

    public function test_command_presentation_has_typed_status_inventory_and_progress_boundaries(): void
    {
        $this->assertTrue(class_exists(SeasonvarQueueStatusPresenter::class));
        $this->assertTrue(class_exists(SeasonvarSourceInventoryPresenter::class));
        $this->assertTrue(class_exists(SeasonvarProgressPresenter::class));
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    private function constructorDependencyNames(string $class): array
    {
        $constructor = (new ReflectionClass($class))->getConstructor();

        if ($constructor === null) {
            return [];
        }

        return collect($constructor->getParameters())
            ->map(static function ($parameter): ?string {
                $type = $parameter->getType();

                return $type instanceof ReflectionNamedType && ! $type->isBuiltin()
                    ? $type->getName()
                    : null;
            })
            ->filter()
            ->values()
            ->all();
    }
}
