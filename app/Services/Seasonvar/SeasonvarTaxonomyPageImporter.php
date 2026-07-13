<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarMetadataPageData;
use App\DTOs\Seasonvar\SeasonvarPageHandlerResult;
use App\Enums\SeasonvarPageType;
use App\Models\SeasonvarImportEvent;
use App\Models\SourcePage;
use App\Services\Catalog\CatalogTaxonomyRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final readonly class SeasonvarTaxonomyPageImporter
{
    public function __construct(
        private CatalogTaxonomyRegistry $taxonomies,
        private SeasonvarTaxonomyIdentity $identity,
        private SeasonvarDiscoveredPageStore $pages,
        private SeasonvarDatabaseTransaction $transactions,
    ) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function import(SourcePage $page, SeasonvarMetadataPageData $data, ?int $importRunId = null, ?callable $progress = null): SeasonvarPageHandlerResult
    {
        $filterType = $data->pageType->value;
        $modelClass = $this->taxonomies->modelClass($filterType);
        $result = $this->transactions->run(function () use ($data, $modelClass): array {
            $taxonomy = $this->resolve($modelClass, $data);
            $created = ! $taxonomy->exists;
            $before = $taxonomy->exists ? $taxonomy->only(['name', 'slug', 'source_url']) : [];
            $sourceUrl = $this->preservedSourceUrl($taxonomy, $data->canonicalSourceUrl);
            $slug = $taxonomy->slug ?: $this->uniqueSlug($modelClass, $data, $sourceUrl);

            $taxonomy->fill([
                'name' => $data->displayName,
                'slug' => $slug,
                'source_url' => $sourceUrl,
            ])->save();

            return [
                'taxonomy' => $taxonomy,
                'created' => $created,
                'changed' => $created || $before !== $taxonomy->only(['name', 'slug', 'source_url']),
            ];
        },
            attempts: min(10, max(1, (int) config('seasonvar.import.transaction_attempts', 5))),
            baseDelayMilliseconds: min(5000, max(0, (int) config('seasonvar.import.transaction_retry_delay_ms', 250))),
            progress: $progress,
        );
        $deferMinutes = max(1, (int) config('seasonvar.import.linked_serial_defer_minutes', 5));
        $this->pages->store($data->linkedSerialUrls, $page->url, $deferMinutes, $progress);
        $event = $result['created']
            ? 'seasonvar-taxonomy-created'
            : ($result['changed'] ? 'seasonvar-taxonomy-updated' : 'seasonvar-taxonomy-duplicate-prevented');
        $context = [
            'page_type' => $data->pageType->value,
            'taxonomy_id' => $result['taxonomy']->id,
            'structured_fields' => $this->structuredFields($data),
            'linked_serial_urls_found' => count($data->linkedSerialUrls),
        ];
        $this->recordEvent($page, $importRunId, $event, $context);
        $this->report($progress, $event, ['source_page_id' => $page->id, ...$context]);

        return new SeasonvarPageHandlerResult(
            linkedSerialUrls: count($data->linkedSerialUrls),
            taxonomiesCreated: $result['created'] ? 1 : 0,
            taxonomiesUpdated: ! $result['created'] && $result['changed'] ? 1 : 0,
            duplicatesPrevented: ! $result['changed'] ? 1 : 0,
            structuredFields: $this->structuredFields($data),
            missingDataFlags: $data->missingDataFlags,
        );
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function resolve(string $modelClass, SeasonvarMetadataPageData $data): Model
    {
        $bySourceUrl = $modelClass::query()->where('source_url', $data->canonicalSourceUrl)->first();

        if ($bySourceUrl !== null) {
            return $bySourceUrl;
        }

        $baseSlug = $this->identity->slug($data->pageType, $data->displayName, $data->canonicalSourceUrl);
        $bySlug = $modelClass::query()->where('slug', $baseSlug)->first();

        if ($bySlug === null) {
            return new $modelClass;
        }

        if ($data->pageType !== SeasonvarPageType::Actor || $bySlug->source_url === null || $bySlug->source_url === '') {
            return $bySlug;
        }

        return new $modelClass;
    }

    /** @param class-string<Model> $modelClass */
    private function uniqueSlug(string $modelClass, SeasonvarMetadataPageData $data, string $sourceUrl): string
    {
        $base = $this->identity->slug($data->pageType, $data->displayName, $sourceUrl);

        if (! $modelClass::query()->where('slug', $base)->exists()) {
            return $base;
        }

        return Str::limit($base, 235, '').'-'.substr($this->identity->stableHash($data->pageType, $sourceUrl), 0, 12);
    }

    private function preservedSourceUrl(Model $taxonomy, string $canonicalSourceUrl): string
    {
        $existing = trim((string) $taxonomy->getAttribute('source_url'));

        return $existing !== '' ? $existing : $canonicalSourceUrl;
    }

    /** @return list<string> */
    private function structuredFields(SeasonvarMetadataPageData $data): array
    {
        return collect([
            'display_name',
            'source_slug',
            'source_url',
            'canonical_source_url',
            $data->pageTitle !== null ? 'page_title' : null,
            $data->alphabetPosition !== null ? 'alphabet_position' : null,
            $data->sourceProvidedCount !== null ? 'source_provided_count' : null,
            $data->linkedSerialUrls !== [] ? 'linked_serial_urls' : null,
        ])->filter()->values()->all();
    }

    /** @param array<string, mixed> $context */
    private function recordEvent(SourcePage $page, ?int $importRunId, string $event, array $context): void
    {
        SeasonvarImportEvent::query()->create([
            'seasonvar_import_run_id' => $importRunId,
            'source_page_id' => $page->id,
            'event' => $event,
            'level' => 'info',
            'context' => $context,
        ]);
    }

    /** @param (callable(string, array<string, mixed>): void)|null $progress */
    private function report(?callable $progress, string $event, array $context): void
    {
        if ($progress !== null) {
            $progress($event, $context);
        }
    }
}
