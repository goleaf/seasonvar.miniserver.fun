<?php

namespace App\Services\Catalog;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use UnitEnum;

class CatalogStatsSnapshotSanitizer
{
    private const HIDDEN_VALUE = 'скрыто';

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sanitize(array $data): array
    {
        $sanitized = $this->sanitizeValue($data);

        return is_array($sanitized) ? $sanitized : [];
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        if (is_array($value)) {
            $clean = [];

            foreach ($value as $key => $item) {
                $clean[$key] = $this->sanitizeValue($item);
            }

            return $clean;
        }

        if (is_string($value)) {
            return $this->sanitizeString($value);
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return null;
    }

    private function sanitizeString(string $value): string
    {
        if ($this->containsUnsafeUrl($value) || $this->containsPublicSourceMarker($value)) {
            return self::HIDDEN_VALUE;
        }

        return $value;
    }

    private function containsPublicSourceMarker(string $value): bool
    {
        foreach ($this->allowedHosts() as $host) {
            $value = str_ireplace($host, '', $value);
        }

        return preg_match('/seasonvar|сезонвар/iu', $value) === 1;
    }

    private function containsUnsafeUrl(string $value): bool
    {
        preg_match_all('~https?://[^\s<>"\']+~iu', $value, $matches);

        foreach ($matches[0] as $url) {
            $host = parse_url($url, PHP_URL_HOST);

            if (! is_string($host) || trim($host) === '') {
                continue;
            }

            if (! $this->isAllowedHost($host)) {
                return true;
            }
        }

        return false;
    }

    private function isAllowedHost(string $host): bool
    {
        return in_array(Str::lower($host), $this->allowedHosts(), true);
    }

    /**
     * @return list<string>
     */
    private function allowedHosts(): array
    {
        return collect([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            request()->getHost(),
            'localhost',
            '127.0.0.1',
        ])
            ->filter()
            ->map(fn (string $allowedHost): string => Str::lower($allowedHost))
            ->unique()
            ->all();
    }
}
