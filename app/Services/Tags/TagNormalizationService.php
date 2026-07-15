<?php

declare(strict_types=1);

namespace App\Services\Tags;

use Illuminate\Support\Str;
use Normalizer;

final class TagNormalizationService
{
    public function display(mixed $value): string
    {
        $value = (string) $value;
        $normalized = class_exists(Normalizer::class)
            ? Normalizer::normalize($value, Normalizer::FORM_C)
            : $value;
        $value = $normalized === false ? $value : $normalized;
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strip_tags($value);
        $value = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}"], ' ', $value);
        $value = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $value) ?? '';
        $value = preg_replace('/^\s*#+\s*/u', '', $value) ?? $value;

        return Str::squish($value);
    }

    public function containsUnsafeInput(mixed $value): bool
    {
        $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return strip_tags($value) !== $value
            || preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1;
    }

    public function comparison(mixed $value): string
    {
        $value = $this->display($value);
        $normalized = class_exists(Normalizer::class)
            ? Normalizer::normalize($value, Normalizer::FORM_KC)
            : $value;
        $value = $normalized === false ? $value : $normalized;
        $value = preg_replace('/\p{Pd}+/u', '-', $value) ?? $value;
        $value = preg_replace('/\s*[-‐‑‒–—―]\s*/u', '-', $value) ?? $value;
        $value = preg_replace('/\s*([:;,\/|])\s*/u', '$1', $value) ?? $value;

        return mb_strtolower(Str::squish($value));
    }

    public function hash(mixed $value): string
    {
        return hash('sha256', $this->comparison($value));
    }

    public function hasMeaningfulContent(string $value): bool
    {
        return preg_match('/[\p{L}\p{N}]/u', $value) === 1;
    }

    public function isReserved(string $value, ?string $locale = null): bool
    {
        $needle = $this->comparison($value);
        $locales = $locale === null
            ? (array) config('tags.supported_locales', [])
            : [$locale];

        foreach ($locales as $candidateLocale) {
            foreach ((array) config("tags.reserved_names.{$candidateLocale}", []) as $reserved) {
                if ($needle === $this->comparison($reserved)) {
                    return true;
                }
            }
        }

        return false;
    }
}
