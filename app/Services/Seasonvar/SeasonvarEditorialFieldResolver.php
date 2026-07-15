<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarCatalogData;
use App\Enums\CatalogPublicationType;
use App\Models\CatalogTitle;

final class SeasonvarEditorialFieldResolver
{
    /**
     * @return array{values: array<string, mixed>, provider_field_values: array<string, mixed>}
     */
    public function resolve(CatalogTitle $title, SeasonvarCatalogData $data): array
    {
        $incoming = [
            'title' => $data->title,
            'original_title' => $data->originalTitle,
            'description' => $data->description,
            'poster_url' => $data->posterUrl,
        ];

        if ($data->hasPublicationTypeEvidence()) {
            $incoming['type'] = $data->type;
        }
        $previousProviderValues = $this->previousProviderValues($title);
        $values = [];

        foreach ($incoming as $field => $incomingValue) {
            $currentValue = $title->getAttribute($field);
            $values[$field] = $this->resolveValue(
                $title->exists,
                $field,
                $currentValue,
                $incomingValue,
                $previousProviderValues,
            );
        }

        return [
            'values' => $values,
            'provider_field_values' => [
                ...$previousProviderValues,
                ...$incoming,
            ],
        ];
    }

    /** @return array{value: mixed, provider_field_values: array<string, mixed>} */
    public function resolveType(CatalogTitle $title, string $incomingType): array
    {
        $previousProviderValues = $this->previousProviderValues($title);

        return [
            'value' => $this->resolveValue(
                $title->exists,
                'type',
                $title->getAttribute('type'),
                $incomingType,
                $previousProviderValues,
            ),
            'provider_field_values' => [
                ...$previousProviderValues,
                'type' => $incomingType,
            ],
        ];
    }

    /** @param array<string, mixed> $previousProviderValues */
    private function resolveValue(
        bool $exists,
        string $field,
        mixed $currentValue,
        mixed $incomingValue,
        array $previousProviderValues,
    ): mixed {
        if (! $exists || $this->isBlank($currentValue)) {
            return $incomingValue;
        }

        if ($this->isBlank($incomingValue)) {
            return $currentValue;
        }

        if (array_key_exists($field, $previousProviderValues)) {
            return $this->equivalent($currentValue, $previousProviderValues[$field])
                ? $incomingValue
                : $currentValue;
        }

        if ($field === 'type' && $currentValue === CatalogPublicationType::Serial->value) {
            return $incomingValue;
        }

        return $currentValue;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private function equivalent(mixed $left, mixed $right): bool
    {
        return $left === $right;
    }

    /** @return array<string, mixed> */
    private function previousProviderValues(CatalogTitle $title): array
    {
        $stored = $title->getAttribute('provider_field_values');

        if (! is_array($stored)) {
            return [];
        }

        $values = [];

        foreach ($stored as $field => $value) {
            if (is_string($field)) {
                $values[$field] = $value;
            }
        }

        return $values;
    }
}
