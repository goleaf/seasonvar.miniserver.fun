<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use DOMXPath;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class SeasonvarStructuredDataParser
{
    /** @return array<string, mixed> */
    public function parse(DOMXPath $xpath): array
    {
        $fallback = [];

        foreach ($xpath->query('//script[contains(@type, "ld+json")]') ?: [] as $node) {
            $decoded = json_decode(trim($node->textContent), true);

            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                continue;
            }

            foreach ($this->items($decoded) as $item) {
                $fallback = $fallback === [] ? $item : $fallback;
                $types = array_map(
                    fn (mixed $type): string => Str::lower((string) $type),
                    Arr::wrap(Arr::get($item, '@type')),
                );

                if (array_intersect(
                    $types,
                    ['tvseries', 'movie', 'creativework', 'videoobject'],
                ) !== []) {
                    return $item;
                }
            }
        }

        return $fallback;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return list<array<string, mixed>>
     */
    private function items(array $value, int $depth = 0): array
    {
        if ($depth >= $this->maxNestingDepth()) {
            return [];
        }

        if (array_key_exists('@graph', $value) && is_array($value['@graph'])) {
            return $this->items($value['@graph'], $depth + 1);
        }

        if (! array_is_list($value)) {
            return [$value];
        }

        $items = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            array_push($items, ...$this->items($item, $depth + 1));

            if (count($items) >= $this->maxCollectionItems()) {
                return array_slice($items, 0, $this->maxCollectionItems());
            }
        }

        return $items;
    }

    private function maxNestingDepth(): int
    {
        return max(
            4,
            min(128, (int) config('seasonvar.import.parser_max_nesting_depth', 32)),
        );
    }

    private function maxCollectionItems(): int
    {
        return max(
            100,
            min(10_000, (int) config('seasonvar.import.parser_max_collection_items', 5000)),
        );
    }
}
