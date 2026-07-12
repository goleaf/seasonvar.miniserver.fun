<?php

namespace App\Services\Seasonvar;

use Illuminate\Support\Str;

class SeasonvarRelationMetadataNormalizer
{
    /**
     * @var array<string, string>
     */
    private const COUNTRY_ALIASES = [
        'корея южная' => 'Южная Корея',
        'голландия' => 'Нидерланды',
        'республика корея' => 'Южная Корея',
        'южная корея' => 'Южная Корея',
        'российская федерация' => 'Россия',
        'соединенные штаты' => 'США',
        'соединённые штаты' => 'США',
        'советский союз' => 'СССР',
        'united kingdom' => 'Великобритания',
        'united states' => 'США',
        'uk' => 'Великобритания',
        'usa' => 'США',
    ];

    /**
     * @var array<string, string>
     */
    private const CURATED_NETWORKS = [
        'abc' => 'ABC',
        'amc' => 'AMC',
        'bbc' => 'BBC',
        'cbs' => 'CBS',
        'fox' => 'FOX',
        'hbo' => 'HBO',
        'netflix' => 'Netflix',
        'showtime' => 'Showtime',
        'syfy' => 'Syfy',
        'tv tokyo' => 'TV Tokyo',
        'кинотеатр start' => 'START',
        'первый канал' => 'Первый канал',
        'пятница' => 'Пятница',
        'россия 1' => 'Россия 1',
        'стс' => 'СТС',
        'тнт' => 'ТНТ',
    ];

    public function translation(?string $value): ?string
    {
        $value = $this->clean($value);

        if ($value === null || $this->isMetadataPlaceholder($value) || $this->isTranslationNoise($value)) {
            return null;
        }

        $quality = '(?:4320p|2160p|1440p|1080p|720p|576p|540p|480p|360p|240p|full[\s._-]*hd|fhd|uhd|4k|hd|sd)';
        $value = preg_replace('/^(?:'.$quality.'\s*[\/|]\s*)+/iu', '', $value) ?? $value;
        $value = preg_replace('/^'.$quality.'(?=[\pL\pN])/iu', '', $value, 1) ?? $value;
        $value = Str::squish(trim($value, " \t\n\r\0\x0B/|_-.") );

        if ($value === '' || $this->isMetadataPlaceholder($value) || $this->isTranslationNoise($value)) {
            return null;
        }

        if (preg_match('/^(?:original(?:\s+(?:audio|voice))?|оригинал(?:ьная\s+(?:дорожка|озвучка))?|без\s+перевода)$/iu', $value) === 1) {
            return 'Оригинал';
        }

        if (Str::length($value) > 120 || preg_match('/[\pL]/u', $value) !== 1) {
            return null;
        }

        return $value;
    }

    public function country(?string $value): ?string
    {
        $value = $this->clean($value);

        if ($value === null || $this->isMetadataPlaceholder($value)) {
            return null;
        }

        return self::COUNTRY_ALIASES[Str::lower($value)] ?? $value;
    }

    public function status(?string $value): ?string
    {
        $value = $this->clean($value);

        if ($value === null || $this->isMetadataPlaceholder($value)) {
            return null;
        }

        $normalized = Str::lower(trim($value, " \t\n\r\0\x0B.!?"));

        return match ($normalized) {
            'идет', 'идёт', 'выходит', 'продолжается', 'в эфире' => 'Выходит',
            'завершен', 'завершён', 'закончен', 'закончено', 'окончен' => 'Завершён',
            'анонс', 'анонсирован', 'анонсировано', 'ожидается', 'скоро' => 'Анонсирован',
            'заморожен', 'заморожено', 'приостановлен', 'приостановлено' => 'Приостановлен',
            'отменен', 'отменён', 'отменено' => 'Отменён',
            default => null,
        };
    }

    public function curatedNetwork(?string $value): ?string
    {
        $value = $this->clean($value);

        if ($value === null || $this->isMetadataPlaceholder($value)) {
            return null;
        }

        return self::CURATED_NETWORKS[Str::lower($value)] ?? null;
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string) Str::of($value)->replace("\xc2\xa0", ' ')->squish();

        return $value !== '' ? $value : null;
    }

    private function isMetadataPlaceholder(string $value): bool
    {
        $normalized = Str::lower(Str::squish($value));

        return in_array($normalized, [
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

    private function isTranslationNoise(string $value): bool
    {
        return preg_match('/(?:трейлер|анонс|тизер|promo|preview|teaser|trailer|субтитр|subtitles?|subs?)/iu', $value) === 1;
    }
}
