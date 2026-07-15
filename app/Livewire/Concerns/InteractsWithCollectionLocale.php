<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\App;
use Livewire\Attributes\Locked;

trait InteractsWithCollectionLocale
{
    #[Locked]
    public string $interfaceLocale = 'ru';

    public function bootInteractsWithCollectionLocale(): void
    {
        $this->applyCollectionLocale($this->interfaceLocale);
    }

    protected function setCollectionLocale(?string $locale): void
    {
        $locale = is_string($locale) ? $locale : App::currentLocale();
        $this->interfaceLocale = $this->applyCollectionLocale($locale);
    }

    /** @return list<string> */
    protected function collectionLocales(): array
    {
        $configured = config('catalog-collections.supported_locales', ['ru']);
        $locales = [];

        foreach (is_array($configured) ? $configured : [] as $locale) {
            if (is_string($locale) && preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D', $locale) === 1) {
                $locales[] = $locale;
            }
        }

        return array_values(array_unique($locales !== [] ? $locales : ['ru']));
    }

    private function applyCollectionLocale(string $locale): string
    {
        $supported = $this->collectionLocales();
        $locale = in_array($locale, $supported, true)
            ? $locale
            : (string) config('catalog-collections.default_locale', 'ru');
        App::setLocale($locale);

        return $locale;
    }
}
