<?php

declare(strict_types=1);

namespace App\Services\HelpCenter;

final class HelpLocale
{
    /** @return list<string> */
    public function supported(): array
    {
        return array_values(array_filter(
            (array) config('help-center.supported_locales', ['ru']),
            static fn (mixed $locale): bool => is_string($locale) && preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D', $locale) === 1,
        ));
    }

    public function normalize(?string $locale): string
    {
        return is_string($locale) && in_array($locale, $this->supported(), true)
            ? $locale
            : $this->fallback();
    }

    public function fallback(): string
    {
        $fallback = (string) config('help-center.fallback_locale', 'ru');

        return in_array($fallback, $this->supported(), true) ? $fallback : ($this->supported()[0] ?? 'ru');
    }
}
