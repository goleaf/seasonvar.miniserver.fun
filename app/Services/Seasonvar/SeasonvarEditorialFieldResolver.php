<?php

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarCatalogData;
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
        $previousProviderValues = is_array($title->provider_field_values)
            ? $title->provider_field_values
            : [];
        $values = [];

        foreach ($incoming as $field => $incomingValue) {
            $currentValue = $title->getAttribute($field);

            if (! $title->exists || $this->isBlank($currentValue)) {
                $values[$field] = $incomingValue;

                continue;
            }

            if ($this->isBlank($incomingValue)) {
                $values[$field] = $currentValue;

                continue;
            }

            $values[$field] = array_key_exists($field, $previousProviderValues)
                && $this->equivalent($currentValue, $previousProviderValues[$field])
                    ? $incomingValue
                    : $currentValue;
        }

        return [
            'values' => $values,
            'provider_field_values' => $incoming,
        ];
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private function equivalent(mixed $left, mixed $right): bool
    {
        return $left === $right;
    }
}
