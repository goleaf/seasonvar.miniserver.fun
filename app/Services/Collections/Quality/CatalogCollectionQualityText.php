<?php

declare(strict_types=1);

namespace App\Services\Collections\Quality;

use Illuminate\Support\Str;
use Normalizer;

final class CatalogCollectionQualityText
{
    public function normalize(string $value): string
    {
        if (class_exists(Normalizer::class)) {
            $value = Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value;
        }

        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^\p{L}\p{N}]+/u', ' ')
            ->squish()
            ->toString();
    }

    public function textHash(string $name, ?string $description): string
    {
        return hash('sha256', $this->normalize($name).'|'.$this->normalize((string) $description));
    }

    /** @param iterable<int> $catalogTitleIds */
    public function contentSignature(iterable $catalogTitleIds): string
    {
        $ids = [];

        foreach ($catalogTitleIds as $catalogTitleId) {
            $id = (int) $catalogTitleId;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        sort($ids, SORT_NUMERIC);

        return hash('sha256', implode(',', $ids));
    }

    public function similarity(string $left, string $right): float
    {
        return $this->similarityTokenSets(
            $this->tokens($left),
            $this->tokens($right),
        );
    }

    /**
     * @param  array<string, true>  $leftTokens
     * @param  array<string, true>  $rightTokens
     */
    public function similarityTokenSets(array $leftTokens, array $rightTokens): float
    {
        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }

        return count(array_intersect_key($leftTokens, $rightTokens))
            / count($leftTokens + $rightTokens);
    }

    /** @return array<string, true> */
    public function tokens(string $value): array
    {
        $tokens = [];

        foreach (explode(' ', $this->normalize($value)) as $token) {
            if (Str::length($token) < 2) {
                continue;
            }

            $stem = Str::length($token) > 7 ? Str::substr($token, 0, 7) : $token;
            $tokens[$stem] = true;
        }

        return $tokens;
    }
}
