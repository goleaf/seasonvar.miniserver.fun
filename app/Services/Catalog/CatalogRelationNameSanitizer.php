<?php

namespace App\Services\Catalog;

use Illuminate\Support\Str;

class CatalogRelationNameSanitizer
{
    /**
     * @var array<string, true>
     */
    private const COUNTRY_NAMES = [
        'австралия' => true,
        'австрия' => true,
        'аргентина' => true,
        'беларусь' => true,
        'бельгия' => true,
        'болгария' => true,
        'бразилия' => true,
        'великобритания' => true,
        'венгрия' => true,
        'венесуэла' => true,
        'германия' => true,
        'гонконг' => true,
        'греция' => true,
        'дания' => true,
        'израиль' => true,
        'индия' => true,
        'ирландия' => true,
        'испания' => true,
        'италия' => true,
        'казахстан' => true,
        'канада' => true,
        'китай' => true,
        'колумбия' => true,
        'корея' => true,
        'корея северная' => true,
        'корея южная' => true,
        'латвия' => true,
        'литва' => true,
        'люксембург' => true,
        'мексика' => true,
        'нидерланды' => true,
        'норвегия' => true,
        'новая зеландия' => true,
        'перу' => true,
        'польша' => true,
        'португалия' => true,
        'россия' => true,
        'румыния' => true,
        'сербия' => true,
        'сингапур' => true,
        'словения' => true,
        'ссср' => true,
        'сша' => true,
        'таиланд' => true,
        'турция' => true,
        'украина' => true,
        'финляндия' => true,
        'франция' => true,
        'хорватия' => true,
        'чехия' => true,
        'чили' => true,
        'швейцария' => true,
        'швеция' => true,
        'эстония' => true,
        'юар' => true,
        'южная корея' => true,
        'япония' => true,
    ];

    public function normalize(string $name): string
    {
        return Str::squish(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function isValid(string $type, string $name): bool
    {
        $name = $this->normalize($name);

        if ($name === '' || Str::length($name) > 120) {
            return false;
        }

        return match ($type) {
            'age_rating' => preg_match('/^\d{1,2}\+?$/u', $name) === 1,
            'country' => $this->isCountryName($name),
            'genre', 'status', 'network', 'studio', 'tag' => $this->isShortCatalogLabel($name),
            'translation' => $this->isValidTranslationName($name),
            default => true,
        };
    }

    private function isShortCatalogLabel(string $name): bool
    {
        return Str::length($name) <= 80
            && preg_match('/[.!?]|(?:главн|добро пожаловать|типичная жизнь|сериал)/iu', $name) !== 1;
    }

    private function isValidTranslationName(string $name): bool
    {
        if (! $this->isShortCatalogLabel($name)) {
            return false;
        }

        $normalized = Str::lower($name);

        if (preg_match('/^(?:19|20)\d{2}$/u', $normalized) === 1) {
            return false;
        }

        if (preg_match('/^\d{1,2}\.\d{1,2}\.\d{4}/u', $normalized) === 1) {
            return false;
        }

        if (preg_match('/^(?:рус|русский|англ|английский|eng|en|ru)\.?$/iu', $name) === 1) {
            return false;
        }

        if (preg_match('/(?:финал\s+сезона|season\s+finale|finale|сер(?:ия|ии|ий)|\bиз\s*\d+)/iu', $name) === 1) {
            return false;
        }

        if (preg_match('/^версия\s+/iu', $name) === 1) {
            return false;
        }

        return ! $this->isCountryName($name);
    }

    private function isCountryName(string $name): bool
    {
        $normalized = Str::lower($this->normalize($name));

        return isset(self::COUNTRY_NAMES[$normalized]);
    }
}
