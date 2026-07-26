<?php

declare(strict_types=1);

namespace App\Services\Catalog\Quality;

use App\DTOs\CatalogQuality\CatalogMetadataProvenanceViewData;
use App\Enums\CatalogMetadataConflictStatus;
use App\Enums\CatalogMetadataField;
use App\Enums\CatalogMetadataSourceKind;
use App\Enums\TagProviderMappingStatus;
use App\Enums\TagSource;
use App\Models\CatalogFieldVersion;
use App\Models\CatalogMetadataConflict;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleTagSource;
use App\Models\TagProviderMapping;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class CatalogMetadataProvenanceQuery
{
    private const UNPROVEN_TAG_CONFIDENCE = 12;

    private const LEGACY_VALUE_CONFIDENCE = 60;

    private ?bool $schemaIsAvailable = null;

    /**
     * @param  iterable<int, int|string>  $titleIds
     * @return Collection<int, list<CatalogMetadataProvenanceViewData>>
     */
    public function forTitleIds(iterable $titleIds): Collection
    {
        $ids = collect($titleIds)
            ->filter(static fn (int|string $id): bool => is_int($id) || ctype_digit($id))
            ->map(static fn (int|string $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->take(50)
            ->values();

        if ($ids->isEmpty() || ! $this->schemaAvailable()) {
            return collect();
        }

        $titles = CatalogTitle::query()
            ->select([
                'id',
                'title',
                'original_title',
                'type',
                'year',
                'description',
                'poster_url',
            ])
            ->with([
                'genres:id,name',
                'countries:id,name',
                'tags:id,name,type,moderation_status,visibility',
            ])
            ->whereKey($ids->all())
            ->get()
            ->keyBy('id');
        $versions = CatalogFieldVersion::query()
            ->select([
                'id',
                'catalog_title_id',
                'field_key',
                'observation_id',
                'source_kind',
                'value',
                'selected_at',
            ])
            ->with([
                'observation:id,source_id,confidence,is_publication_eligible,last_confirmed_at',
                'observation.source:id,name,code',
            ])
            ->whereIn('catalog_title_id', $ids->all())
            ->whereNull('superseded_at')
            ->get()
            ->groupBy('catalog_title_id');
        $conflicts = CatalogMetadataConflict::query()
            ->whereIn('catalog_title_id', $ids->all())
            ->where('status', CatalogMetadataConflictStatus::Open->value)
            ->get(['catalog_title_id', 'field_key'])
            ->groupBy('catalog_title_id')
            ->map(
                static fn (Collection $rows): Collection => $rows->pluck('field_key')
                    ->map(static fn (CatalogMetadataField $field): string => $field->value),
            );
        $tagSources = CatalogTitleTagSource::query()
            ->select([
                'id',
                'catalog_title_id',
                'tag_id',
                'source',
                'provider',
                'source_id',
                'source_key',
                'last_seen_at',
            ])
            ->with('providerSource:id,name,code')
            ->whereIn('catalog_title_id', $ids->all())
            ->where('is_current', true)
            ->get()
            ->groupBy(
                static fn (CatalogTitleTagSource $source): string => implode(':', [
                    $source->catalog_title_id,
                    $source->tag_id,
                ]),
            );
        $mappingConfidence = $this->mappingConfidence($tagSources->flatten(1));

        return $ids->mapWithKeys(function (int $titleId) use (
            $titles,
            $versions,
            $conflicts,
            $tagSources,
            $mappingConfidence,
        ): array {
            $title = $titles->get($titleId);

            if (! $title instanceof CatalogTitle) {
                return [];
            }

            $titleVersions = $versions->get($titleId, collect())
                ->keyBy(
                    static fn (CatalogFieldVersion $version): string => $version->field_key->value,
                );
            $titleConflicts = $conflicts->get($titleId, collect());
            $rows = collect(CatalogMetadataField::cases())
                ->map(fn (CatalogMetadataField $field): CatalogMetadataProvenanceViewData => $this->fieldRow(
                    $title,
                    $field,
                    $titleVersions->get($field->value),
                    $titleConflicts->contains($field->value),
                ));

            foreach ($title->tags->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->take(24) as $tag) {
                $sources = $tagSources->get($titleId.':'.(int) $tag->id, collect());
                $rows->push($this->tagRow($tag->name, (int) $tag->id, $sources, $mappingConfidence));
            }

            return [$titleId => array_values($rows->all())];
        });
    }

    private function fieldRow(
        CatalogTitle $title,
        CatalogMetadataField $field,
        mixed $version,
        bool $hasConflict,
    ): CatalogMetadataProvenanceViewData {
        if ($version instanceof CatalogFieldVersion) {
            if ($version->observation_id === null) {
                $confidence = $version->source_kind === CatalogMetadataSourceKind::Legacy
                    ? self::LEGACY_VALUE_CONFIDENCE
                    : 100;

                return $this->row(
                    key: 'field-'.$field->value,
                    field: $field->value,
                    value: $version->value,
                    source: $this->fieldSourceLabel($version),
                    confirmedAt: $version->selected_at,
                    confidence: $confidence,
                    eligible: $confidence >= 80,
                    conflict: $hasConflict,
                );
            }

            return $this->row(
                key: 'field-'.$field->value,
                field: $field->value,
                value: $version->value,
                source: $this->fieldSourceLabel($version),
                confirmedAt: $version->observation->last_confirmed_at,
                confidence: $version->observation->confidence,
                eligible: $version->observation->is_publication_eligible,
                conflict: $hasConflict,
            );
        }

        $value = $this->currentValue($title, $field);
        $blank = $value === null || $value === '' || $value === [];

        return $this->row(
            key: 'field-'.$field->value,
            field: $field->value,
            value: $value,
            source: __('catalog-quality.provenance.sources.unrecorded'),
            confirmedAt: null,
            confidence: $blank ? 0 : self::LEGACY_VALUE_CONFIDENCE,
            eligible: false,
            conflict: $hasConflict,
        );
    }

    /**
     * @param  Collection<int, CatalogTitleTagSource>  $sources
     * @param  Collection<string, array{confidence: int, status: TagProviderMappingStatus}>  $mappingConfidence
     */
    private function tagRow(
        string $name,
        int $tagId,
        Collection $sources,
        Collection $mappingConfidence,
    ): CatalogMetadataProvenanceViewData {
        $source = $sources
            ->sortByDesc(static fn (CatalogTitleTagSource $candidate): int => match ($candidate->source) {
                TagSource::Editorial => 3,
                TagSource::System => 2,
                default => 1,
            })
            ->first();
        $confidence = self::UNPROVEN_TAG_CONFIDENCE;
        $eligible = false;
        $sourceLabel = __('catalog-quality.provenance.sources.imported_relation');
        $confirmedAt = null;

        if ($source instanceof CatalogTitleTagSource) {
            $confirmedAt = $source->last_seen_at;

            if ($source->source === TagSource::Editorial) {
                $confidence = 100;
                $eligible = true;
                $sourceLabel = __('catalog-quality.provenance.sources.editorial');
            } elseif ($source->source === TagSource::System) {
                $confidence = 100;
                $eligible = true;
                $sourceLabel = __('catalog-quality.provenance.sources.system');
            } else {
                $mapping = $mappingConfidence->get((string) $source->source_key);
                $confidence = $mapping['confidence'] ?? 80;
                $eligible = isset($mapping)
                    && $mapping['status'] === TagProviderMappingStatus::Approved
                    && $confidence >= 60;
                $sourceLabel = $source->source_id !== null
                    ? $source->providerSource->name
                    : __('catalog-quality.provenance.sources.seasonvar');
            }
        }

        return $this->row(
            key: 'tag-'.$tagId,
            field: 'tags',
            value: $name,
            source: $sourceLabel,
            confirmedAt: $confirmedAt,
            confidence: $confidence,
            eligible: $eligible,
            conflict: false,
        );
    }

    /**
     * @param  Collection<int, CatalogTitleTagSource>  $sources
     * @return Collection<string, array{confidence: int, status: TagProviderMappingStatus}>
     */
    private function mappingConfidence(Collection $sources): Collection
    {
        $keys = $sources
            ->filter(static fn (CatalogTitleTagSource $source): bool => $source->provider === 'seasonvar')
            ->pluck('source_key')
            ->filter(static fn (mixed $key): bool => is_string($key))
            ->unique()
            ->values();

        if ($keys->isEmpty()) {
            return collect();
        }

        return TagProviderMapping::query()
            ->where('provider', 'seasonvar')
            ->whereIn('provider_key', $keys->all())
            ->get(['provider_key', 'confidence', 'status'])
            ->mapWithKeys(
                static fn (TagProviderMapping $mapping): array => [
                    $mapping->provider_key => [
                        'confidence' => $mapping->confidence,
                        'status' => $mapping->status,
                    ],
                ],
            );
    }

    private function row(
        string $key,
        string $field,
        mixed $value,
        string $source,
        ?CarbonInterface $confirmedAt,
        int $confidence,
        bool $eligible,
        bool $conflict,
    ): CatalogMetadataProvenanceViewData {
        $status = $conflict
            ? 'conflict'
            : ($eligible && $confidence >= 60 ? 'confirmed' : 'review');

        return new CatalogMetadataProvenanceViewData(
            key: $key,
            fieldLabel: __("catalog-quality.fields.{$field}"),
            valueLabel: $this->valueLabel($field, $value),
            sourceLabel: $source,
            confirmedAtLabel: $confirmedAt !== null
                ? $confirmedAt->locale(app()->getLocale())->isoFormat('D MMM YYYY, HH:mm')
                : __('catalog-quality.values.never'),
            confidence: max(0, min(100, $confidence)),
            status: $status,
            statusLabel: __("catalog-quality.provenance.statuses.{$status}"),
        );
    }

    private function fieldSourceLabel(CatalogFieldVersion $version): string
    {
        return match ($version->source_kind) {
            CatalogMetadataSourceKind::Editorial => __('catalog-quality.provenance.sources.editorial'),
            CatalogMetadataSourceKind::Provider => __('catalog-quality.provenance.sources.seasonvar'),
            CatalogMetadataSourceKind::Legacy => __('catalog-quality.provenance.sources.legacy'),
        };
    }

    private function currentValue(CatalogTitle $title, CatalogMetadataField $field): mixed
    {
        return match ($field) {
            CatalogMetadataField::Genres => $title->genres->pluck('name')->sort()->values()->all(),
            CatalogMetadataField::Countries => $title->countries->pluck('name')->sort()->values()->all(),
            default => $title->getAttribute($field->value),
        };
    }

    private function valueLabel(string $field, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return __('catalog-quality.values.unknown');
        }

        if ($field === CatalogMetadataField::PosterUrl->value) {
            return __('catalog-quality.provenance.poster_present');
        }

        if (is_array($value)) {
            return implode(', ', array_slice(array_values(array_filter($value, is_string(...))), 0, 12));
        }

        $value = is_scalar($value) ? (string) $value : __('catalog-quality.values.unknown');

        return $field === CatalogMetadataField::Description->value
            ? Str::limit(Str::squish($value), 140)
            : Str::limit(Str::squish($value), 100);
    }

    private function schemaAvailable(): bool
    {
        return $this->schemaIsAvailable ??= Schema::hasTable('catalog_metadata_observations')
            && Schema::hasTable('catalog_metadata_conflicts')
            && Schema::hasTable('catalog_field_versions')
            && Schema::hasTable('catalog_title_tag_sources')
            && Schema::hasTable('tag_provider_mappings');
    }
}
