<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use Illuminate\Support\Str;
use Normalizer;

final class SeasonvarTaxonomyIdentity
{
    public function displayName(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = class_exists(Normalizer::class)
            ? (Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value)
            : $value;
        $value = (string) Str::of($value)->replace("\xc2\xa0", ' ')->squish();
        $value = preg_replace('/^[\pP\pS\s]+|[\pP\pS\s]+$/u', '', $value) ?? $value;

        return Str::limit(Str::squish($value), 160, '');
    }

    public function comparisonKey(string $value): string
    {
        $value = Str::lower($this->displayName($value));
        $value = str_replace('ё', 'е', $value);
        $value = preg_replace('/[\pP\pS]+/u', ' ', $value) ?? $value;

        return Str::squish($value);
    }

    /** @return list<string> */
    public function sourceAliases(string $displayName, string $sourceSlug): array
    {
        return collect([
            $this->comparisonKey($displayName),
            $this->comparisonKey(rawurldecode(str_replace(['-', '_'], ' ', $sourceSlug))),
        ])->filter()->unique()->values()->all();
    }
}
