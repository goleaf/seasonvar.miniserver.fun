<?php

namespace App\Services\Catalog;

use Illuminate\Support\Str;
use Normalizer;

class CatalogRelationNameSanitizer
{
    /**
     * @var array<string, true>
     */
    private const COUNTRY_NAMES = [
        'австралия' => true,
        'австрия' => true,
        'аргентина' => true,
        'армения' => true,
        'беларусь' => true,
        'бельгия' => true,
        'болгария' => true,
        'бразилия' => true,
        'великобритания' => true,
        'венгрия' => true,
        'венесуэла' => true,
        'вьетнам' => true,
        'германия' => true,
        'гонконг' => true,
        'грузия' => true,
        'греция' => true,
        'дания' => true,
        'израиль' => true,
        'индия' => true,
        'индонезия' => true,
        'ирландия' => true,
        'исландия' => true,
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
        'ливан' => true,
        'литва' => true,
        'люксембург' => true,
        'малайзия' => true,
        'мексика' => true,
        'нидерланды' => true,
        'норвегия' => true,
        'новая зеландия' => true,
        'оаэ' => true,
        'пакистан' => true,
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
        'тайвань' => true,
        'турция' => true,
        'украина' => true,
        'финляндия' => true,
        'франция' => true,
        'филиппины' => true,
        'хорватия' => true,
        'чехия' => true,
        'чехословакия' => true,
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
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = Normalizer::normalize($name, Normalizer::FORM_KC);

        return Str::squish($normalized === false ? $name : $normalized);
    }

    public function canonicalKey(string $type, string $name): string
    {
        $name = $this->normalize($name);
        $identityName = in_array($type, ['actor', 'director'], true)
            ? Str::transliterate($name, 'en')
            : $name;
        $slug = Str::slug($identityName);

        return $slug !== ''
            ? $slug
            : Str::substr(hash('sha256', $type.'|'.Str::lower($name)), 0, 32);
    }

    public function preferredName(string $type, string $current, string $incoming): string
    {
        $current = $this->normalize($current);
        $incoming = $this->normalize($incoming);

        if ($current === '') {
            return $incoming;
        }

        if ($incoming === '' || $this->canonicalKey($type, $current) !== $this->canonicalKey($type, $incoming)) {
            return $current;
        }

        if (in_array($type, ['actor', 'director'], true)
            && preg_match('/\p{Cyrillic}/u', $current) !== 1
            && preg_match('/\p{Cyrillic}/u', $incoming) === 1) {
            return $incoming;
        }

        return $current;
    }

    public function isValid(string $type, string $name): bool
    {
        $name = $this->normalize($name);

        if ($name === '' || Str::length($name) > 120 || $this->isPlaceholder($name)) {
            return false;
        }

        return match ($type) {
            'actor', 'director' => $this->isValidPersonName($name),
            'age_rating' => preg_match('/^\d{1,2}\+?$/u', $name) === 1,
            'country' => $this->isCountryName($name),
            'status' => in_array($name, ['Выходит', 'Завершён', 'Анонсирован', 'Приостановлен', 'Отменён'], true),
            'network', 'studio' => $this->isValidOrganizationName($name),
            'genre', 'tag' => $this->isShortCatalogLabel($name),
            'translation' => $this->isValidTranslationName($name),
            default => true,
        };
    }

    private function isShortCatalogLabel(string $name): bool
    {
        return Str::length($name) <= 80
            && preg_match('/[.!?]|(?:главн|добро пожаловать|типичная жизнь|сериал)/iu', $name) !== 1;
    }

    private function isValidOrganizationName(string $name): bool
    {
        return Str::length($name) <= 80
            && preg_match('/[\pL]/u', $name) === 1
            && preg_match('/[!?]|(?:главн|добро пожаловать|типичная жизнь|финал сезона|\bсер(?:ия|ии|ий)\b)/iu', $name) !== 1;
    }

    private function isValidPersonName(string $name): bool
    {
        if (preg_match('/[\pL]/u', $name) !== 1) {
            return false;
        }

        return preg_match('/^(?:>{2,}|(?:сериал|serial|series)\s+)/iu', $name) !== 1
            && preg_match('/(?:\bсерия\s+из\b|\bсерий\s+из\b|сериал\s+полностью|добавлен\w*\s+сезон|season\s+finale)/iu', $name) !== 1
            && preg_match('/\b\d{1,2}\.\d{1,2}\.\d{4}\b.*\b(?:сер(?:ия|ии|ий)|сезон|season)\b/iu', $name) !== 1;
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

    private function isPlaceholder(string $name): bool
    {
        return in_array(Str::lower($this->normalize($name)), [
            '-',
            'нет',
            'не указано',
            'неизвестно',
            'ничего не найдено',
            'отсутствует',
            'рекомендовано',
            'рекомендовано!',
        ], true);
    }
}
