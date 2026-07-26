<?php

declare(strict_types=1);

namespace App\Services\Catalog\Quality;

use App\Enums\CatalogMetadataConflictStatus;
use App\Enums\CatalogMetadataField;
use App\Enums\CatalogMetadataSourceKind;
use App\Enums\CatalogQualitySeverity;
use App\Models\CatalogFieldVersion;
use App\Models\CatalogMetadataConflict;
use App\Models\CatalogMetadataObservation;
use App\Models\CatalogTitle;
use App\Models\SourcePage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class CatalogMetadataProvenanceRecorder
{
    private const PROVIDER_SCALAR_CONFIDENCE = 98;

    private const PROVIDER_TAXONOMY_CONFIDENCE = 96;

    private const INCOMPLETE_TAXONOMY_CONFIDENCE = 70;

    private const MISSING_VALUE_CONFIDENCE = 35;

    private const EDITORIAL_CONFIDENCE = 100;

    private ?bool $schemaIsAvailable = null;

    /**
     * @param  array<string, mixed>  $values
     */
    public function recordProviderSnapshot(
        CatalogTitle $title,
        SourcePage $sourcePage,
        array $values,
        bool $completeTaxonomySnapshot = false,
    ): void {
        if (! $this->schemaAvailable()) {
            return;
        }

        DB::transaction(function () use ($title, $sourcePage, $values, $completeTaxonomySnapshot): void {
            $currentTitle = CatalogTitle::query()->withTrashed()->lockForUpdate()->findOrFail($title->id);
            $sourceKey = hash('sha256', 'provider:seasonvar:'.(int) $sourcePage->source_id);

            foreach ($this->allowedValues($values) as $fieldValue) {
                $field = $fieldValue['field'];
                $rawValue = $fieldValue['value'];
                $normalizedValue = $this->normalize($field, $rawValue);
                [$confidence, $publicationEligible] = $this->providerConfidence(
                    $field,
                    $normalizedValue,
                    $completeTaxonomySnapshot,
                );
                $observation = $this->observe(
                    title: $currentTitle,
                    field: $field,
                    sourceKind: CatalogMetadataSourceKind::Provider,
                    sourceKey: $sourceKey,
                    value: $normalizedValue,
                    confidence: $confidence,
                    publicationEligible: $publicationEligible,
                    sourceId: (int) $sourcePage->source_id,
                    sourcePageId: (int) $sourcePage->id,
                    deactivateAllOfKind: false,
                );
                $selectedValue = $this->selectedValue($currentTitle, $field);
                $selectedObservation = $this->selectedObservation(
                    $currentTitle,
                    $field,
                    $selectedValue,
                    $observation,
                );

                $this->selectVersion(
                    $currentTitle,
                    $field,
                    $selectedValue,
                    $selectedObservation,
                    $selectedObservation !== null
                        ? $selectedObservation->source_kind
                        : CatalogMetadataSourceKind::Legacy,
                    null,
                );
                $this->synchronizeConflict(
                    $currentTitle,
                    $field,
                    $selectedValue,
                    $selectedObservation,
                    $observation,
                );
            }
        }, attempts: 3);
    }

    /**
     * @param  list<string>  $changedFields
     */
    public function recordEditorialSelection(
        CatalogTitle $title,
        User $actor,
        array $changedFields,
    ): void {
        if (! $this->schemaAvailable()) {
            return;
        }

        $fields = collect($changedFields)
            ->map(static fn (string $field): ?CatalogMetadataField => CatalogMetadataField::tryFrom($field))
            ->filter(static fn (?CatalogMetadataField $field): bool => $field !== null && ! $field->isTaxonomy())
            ->unique(static fn (CatalogMetadataField $field): string => $field->value)
            ->values();

        if ($fields->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($title, $actor, $fields): void {
            $currentTitle = CatalogTitle::query()->withTrashed()->lockForUpdate()->findOrFail($title->id);
            $sourceKey = hash('sha256', 'editorial:'.(int) $actor->id);

            foreach ($fields as $field) {
                $value = $this->normalize($field, $currentTitle->getAttribute($field->value));
                $observation = $this->observe(
                    title: $currentTitle,
                    field: $field,
                    sourceKind: CatalogMetadataSourceKind::Editorial,
                    sourceKey: $sourceKey,
                    value: $value,
                    confidence: self::EDITORIAL_CONFIDENCE,
                    publicationEligible: true,
                    sourceId: null,
                    sourcePageId: null,
                    deactivateAllOfKind: true,
                );

                $this->selectVersion(
                    $currentTitle,
                    $field,
                    $value,
                    $observation,
                    CatalogMetadataSourceKind::Editorial,
                    (int) $actor->id,
                );

                $providerObservation = CatalogMetadataObservation::query()
                    ->whereBelongsTo($currentTitle)
                    ->where('field_key', $field->value)
                    ->where('source_kind', CatalogMetadataSourceKind::Provider->value)
                    ->where('is_current', true)
                    ->latest('last_confirmed_at')
                    ->first();

                if ($providerObservation instanceof CatalogMetadataObservation) {
                    $this->synchronizeConflict(
                        $currentTitle,
                        $field,
                        $value,
                        $observation,
                        $providerObservation,
                    );
                }
            }
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<array{field: CatalogMetadataField, value: mixed}>
     */
    private function allowedValues(array $values): array
    {
        $allowed = [];

        foreach (CatalogMetadataField::cases() as $field) {
            if (array_key_exists($field->value, $values)) {
                $allowed[] = [
                    'field' => $field,
                    'value' => $values[$field->value],
                ];
            }
        }

        return $allowed;
    }

    private function observe(
        CatalogTitle $title,
        CatalogMetadataField $field,
        CatalogMetadataSourceKind $sourceKind,
        string $sourceKey,
        mixed $value,
        int $confidence,
        bool $publicationEligible,
        ?int $sourceId,
        ?int $sourcePageId,
        bool $deactivateAllOfKind,
    ): CatalogMetadataObservation {
        $now = now();
        $valueHash = $this->valueHash($value);
        $current = CatalogMetadataObservation::query()
            ->whereBelongsTo($title)
            ->where('field_key', $field->value)
            ->where('source_kind', $sourceKind->value)
            ->where('is_current', true);

        if (! $deactivateAllOfKind) {
            $current->where('source_key', $sourceKey);
        }

        $current
            ->where('value_hash', '!=', $valueHash)
            ->update([
                'is_current' => false,
                'updated_at' => $now,
            ]);

        $observation = CatalogMetadataObservation::query()->firstOrNew([
            'catalog_title_id' => $title->id,
            'field_key' => $field->value,
            'source_kind' => $sourceKind->value,
            'source_key' => $sourceKey,
            'value_hash' => $valueHash,
        ]);
        $observation->fill([
            'source_id' => $sourceId,
            'source_page_id' => $sourcePageId,
            'value' => $value,
            'confidence' => max(0, min(100, $confidence)),
            'is_current' => true,
            'is_publication_eligible' => $publicationEligible,
            'first_observed_at' => $observation->exists
                ? $observation->first_observed_at
                : $now,
            'last_confirmed_at' => $now,
        ])->save();

        if ($deactivateAllOfKind) {
            CatalogMetadataObservation::query()
                ->whereBelongsTo($title)
                ->where('field_key', $field->value)
                ->where('source_kind', $sourceKind->value)
                ->whereKeyNot($observation->id)
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                    'updated_at' => $now,
                ]);
        }

        return $observation;
    }

    private function selectedObservation(
        CatalogTitle $title,
        CatalogMetadataField $field,
        mixed $selectedValue,
        CatalogMetadataObservation $providerObservation,
    ): ?CatalogMetadataObservation {
        $selectedHash = $this->valueHash($selectedValue);

        if ($providerObservation->value_hash === $selectedHash) {
            return $providerObservation;
        }

        return CatalogMetadataObservation::query()
            ->whereBelongsTo($title)
            ->where('field_key', $field->value)
            ->where('value_hash', $selectedHash)
            ->where('is_current', true)
            ->orderByRaw(
                "CASE source_kind WHEN 'editorial' THEN 0 WHEN 'provider' THEN 1 ELSE 2 END",
            )
            ->latest('last_confirmed_at')
            ->first();
    }

    private function selectVersion(
        CatalogTitle $title,
        CatalogMetadataField $field,
        mixed $value,
        ?CatalogMetadataObservation $observation,
        CatalogMetadataSourceKind $sourceKind,
        ?int $actorId,
    ): CatalogFieldVersion {
        $now = now();
        $valueHash = $this->valueHash($value);
        $current = CatalogFieldVersion::query()
            ->whereBelongsTo($title)
            ->where('field_key', $field->value)
            ->whereNull('superseded_at')
            ->lockForUpdate()
            ->latest('version')
            ->first();

        if ($current instanceof CatalogFieldVersion && $current->value_hash === $valueHash) {
            return $current;
        }

        if ($current instanceof CatalogFieldVersion) {
            $current->forceFill(['superseded_at' => $now])->save();
        }

        $nextVersion = (int) CatalogFieldVersion::query()
            ->whereBelongsTo($title)
            ->where('field_key', $field->value)
            ->max('version') + 1;

        return CatalogFieldVersion::query()->create([
            'catalog_title_id' => $title->id,
            'field_key' => $field->value,
            'version' => $nextVersion,
            'observation_id' => $observation?->id,
            'actor_id' => $actorId,
            'source_kind' => $sourceKind->value,
            'value' => $value,
            'value_hash' => $valueHash,
            'selected_at' => $now,
        ]);
    }

    private function synchronizeConflict(
        CatalogTitle $title,
        CatalogMetadataField $field,
        mixed $selectedValue,
        ?CatalogMetadataObservation $selectedObservation,
        CatalogMetadataObservation $providerObservation,
    ): void {
        $now = now();
        $selectedHash = $this->valueHash($selectedValue);

        if ($selectedHash === $providerObservation->value_hash) {
            CatalogMetadataConflict::query()
                ->whereBelongsTo($title)
                ->where('field_key', $field->value)
                ->where('status', CatalogMetadataConflictStatus::Open->value)
                ->update([
                    'status' => CatalogMetadataConflictStatus::Resolved->value,
                    'resolved_at' => $now,
                    'last_detected_at' => $now,
                    'updated_at' => $now,
                ]);

            return;
        }

        $conflict = CatalogMetadataConflict::query()->firstOrNew([
            'catalog_title_id' => $title->id,
            'field_key' => $field->value,
            'selected_value_hash' => $selectedHash,
            'competing_value_hash' => $providerObservation->value_hash,
        ]);
        $conflict->fill([
            'selected_observation_id' => $selectedObservation?->id,
            'competing_observation_id' => $providerObservation->id,
            'severity' => CatalogQualitySeverity::Warning->value,
            'status' => CatalogMetadataConflictStatus::Open->value,
            'first_detected_at' => $conflict->exists
                ? $conflict->first_detected_at
                : $now,
            'last_detected_at' => $now,
            'resolved_at' => null,
        ])->save();
    }

    /** @return array{int, bool} */
    private function providerConfidence(
        CatalogMetadataField $field,
        mixed $value,
        bool $completeTaxonomySnapshot,
    ): array {
        if ($this->isBlank($value)) {
            return [self::MISSING_VALUE_CONFIDENCE, false];
        }

        if ($field->isTaxonomy()) {
            return $completeTaxonomySnapshot
                ? [self::PROVIDER_TAXONOMY_CONFIDENCE, true]
                : [self::INCOMPLETE_TAXONOMY_CONFIDENCE, false];
        }

        return [self::PROVIDER_SCALAR_CONFIDENCE, true];
    }

    private function normalize(CatalogMetadataField $field, mixed $value): mixed
    {
        if ($field->isTaxonomy()) {
            if (! is_array($value)) {
                return [];
            }

            $values = collect($value)
                ->filter(static fn (mixed $item): bool => is_string($item))
                ->map(static fn (string $item): string => Str::squish($item))
                ->filter()
                ->unique()
                ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();

            return $values;
        }

        if ($field === CatalogMetadataField::Year) {
            return is_numeric($value) ? (int) $value : null;
        }

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return $value;
        }

        $value = Str::squish($value);

        return $value !== '' ? $value : null;
    }

    private function selectedValue(
        CatalogTitle $title,
        CatalogMetadataField $field,
    ): mixed {
        $value = match ($field) {
            CatalogMetadataField::Genres => $title->genres()->pluck('name')->all(),
            CatalogMetadataField::Countries => $title->countries()->pluck('name')->all(),
            default => $title->getAttribute($field->value),
        };

        return $this->normalize($field, $value);
    }

    private function valueHash(mixed $value): string
    {
        return hash('sha256', json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private function schemaAvailable(): bool
    {
        return $this->schemaIsAvailable ??= Schema::hasTable('catalog_metadata_observations')
            && Schema::hasTable('catalog_metadata_conflicts')
            && Schema::hasTable('catalog_field_versions');
    }
}
