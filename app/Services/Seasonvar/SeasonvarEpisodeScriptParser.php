<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use Illuminate\Support\Str;

final class SeasonvarEpisodeScriptParser
{
    /**
     * @return list<array{season_number: int, number: int, title: string|null, source_url: string|null}>
     */
    public function parse(string $html, string $baseUrl, int $seasonNumber): array
    {
        $episodes = [];
        $payload = $this->payload($html);
        $decoded = $payload !== null ? json_decode($payload, true) : null;

        if (is_array($decoded)) {
            $this->collect($decoded, $baseUrl, $seasonNumber, $episodes);
        }

        if ($episodes === []) {
            foreach ($this->fallbackNumbers($html) as $number) {
                $episodes[$number] = [
                    'season_number' => $seasonNumber,
                    'number' => $number,
                    'title' => $number.' серия',
                    'source_url' => $baseUrl.'#'.$number.'_seriya',
                ];
            }
        }

        ksort($episodes);

        return array_values($episodes);
    }

    public function payload(string $html): ?string
    {
        if (preg_match(
            '/var\s+arEpisodes\s*=\s*/u',
            $html,
            $matches,
            PREG_OFFSET_CAPTURE,
        ) !== 1) {
            return null;
        }

        return $this->balancedJavascriptValue(
            $html,
            $matches[0][1] + strlen($matches[0][0]),
        );
    }

    private function balancedJavascriptValue(string $text, int $start): ?string
    {
        $length = strlen($text);

        while ($start < $length && ctype_space($text[$start])) {
            $start++;
        }

        if ($start >= $length || ! in_array($text[$start], ['[', '{'], true)) {
            return null;
        }

        $stack = [];
        $stringQuote = null;
        $escaped = false;

        for ($position = $start; $position < $length; $position++) {
            $char = $text[$position];

            if ($stringQuote !== null) {
                if ($escaped) {
                    $escaped = false;

                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;

                    continue;
                }

                if ($char === $stringQuote) {
                    $stringQuote = null;
                }

                continue;
            }

            if (in_array($char, ['"', "'"], true)) {
                $stringQuote = $char;

                continue;
            }

            if ($char === '[' || $char === '{') {
                $stack[] = $char;

                continue;
            }

            if ($char !== ']' && $char !== '}') {
                continue;
            }

            $open = array_pop($stack);

            if (($char === ']' && $open !== '[') || ($char === '}' && $open !== '{')) {
                return null;
            }

            if ($stack === []) {
                return substr($text, $start, $position - $start + 1);
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{season_number: int, number: int, title: string|null, source_url: string|null}>  $episodes
     */
    private function collect(
        mixed $value,
        string $baseUrl,
        int $seasonNumber,
        array &$episodes,
        string|int|null $key = null,
        int $depth = 0,
    ): void {
        if (! is_array($value)
            || $depth >= $this->maxNestingDepth()
            || count($episodes) >= $this->maxCollectionItems()
        ) {
            return;
        }

        if (isset($value['n']) && is_numeric($value['n'])) {
            $number = (int) $value['n'];

            if ($number <= 0) {
                return;
            }

            $slug = is_string($key) && $key !== '' ? $key : $number.'_seriya';
            $title = $this->firstNonEmpty([
                $value['title'] ?? null,
                $value['name'] ?? null,
                $value['t'] ?? null,
            ]);
            $episodes[$number] = [
                'season_number' => $seasonNumber,
                'number' => $number,
                'title' => $title ?? $number.' серия',
                'source_url' => $baseUrl.'#'.$slug,
            ];

            return;
        }

        foreach ($value as $childKey => $childValue) {
            $this->collect(
                $childValue,
                $baseUrl,
                $seasonNumber,
                $episodes,
                $childKey,
                $depth + 1,
            );
        }
    }

    /** @return list<int> */
    private function fallbackNumbers(string $html): array
    {
        $count = preg_match_all(
            '/["\']?n["\']?\s*:\s*["\']?(\d{1,3})["\']?/iu',
            $html,
            $matches,
        );

        if ($count === false || $count === 0) {
            return [];
        }

        return collect($matches[1])
            ->map(fn (string $number): int => (int) $number)
            ->filter(fn (int $number): bool => $number > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @param list<mixed> $values */
    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            $string = $this->stringValue($value);

            if ($string !== null) {
                return $string;
            }
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string) Str::of($value)->replace("\xc2\xa0", ' ')->squish();

        return $value !== '' ? $value : null;
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
