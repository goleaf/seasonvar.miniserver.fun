<?php

declare(strict_types=1);

namespace App\Support\Cache;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class CacheKeyFactory
{
    /** @param array<string, mixed> $dimensions */
    public function data(CacheDomain $domain, string $resource, array $dimensions, int $version): string
    {
        if ($version < 1) {
            throw new InvalidArgumentException('Версия кэша должна быть положительной.');
        }

        $resource = Str::limit(Str::slug($resource), 48, '');

        if ($resource === '') {
            throw new InvalidArgumentException('Ресурс кэша не может быть пустым.');
        }

        $canonical = $this->canonicalize($dimensions);
        $fingerprint = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return implode(':', [
            $this->application(),
            $this->environment(),
            's'.$this->positiveConfig('schema_version'),
            'f'.$this->positiveConfig('format_version'),
            $domain->value,
            'v'.$version,
            $resource,
            $fingerprint,
        ]);
    }

    public function version(CacheDomain $domain, string $scope = 'public'): string
    {
        $scope = Str::limit(Str::slug($scope), 40, '');

        if ($scope === '') {
            throw new InvalidArgumentException('Scope версии кэша не может быть пустым.');
        }

        return implode(':', [
            $this->application(),
            $this->environment(),
            's'.$this->positiveConfig('schema_version'),
            'f'.$this->positiveConfig('format_version'),
            $domain->value,
            $scope,
            'version',
        ]);
    }

    public function modified(CacheDomain $domain, string $scope = 'public'): string
    {
        return $this->version($domain, $scope).':modified';
    }

    public function stale(string $dataKey): string
    {
        return $dataKey.':stale';
    }

    public function lock(string $dataKey): string
    {
        return $dataKey.':rebuild';
    }

    public function metric(CacheDomain $domain, string $metric, string $date): string
    {
        $metric = Str::limit(Str::slug($metric), 48, '');

        if ($metric === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            throw new InvalidArgumentException('Название метрики или дата имеют недопустимый формат.');
        }

        return implode(':', [
            $this->application(),
            $this->environment(),
            'cache-metrics',
            $date,
            $domain->value,
            $metric,
        ]);
    }

    /** @return array<string, mixed> */
    private function canonicalize(array $dimensions): array
    {
        $count = 0;
        $canonical = $this->canonicalValue($dimensions, $count);

        if (! is_array($canonical)) {
            throw new InvalidArgumentException('Измерения кэша должны быть массивом.');
        }

        return $canonical;
    }

    private function canonicalValue(mixed $value, int &$count): mixed
    {
        if (is_array($value)) {
            $count += count($value);

            if ($count > max(1, (int) config('cache-architecture.max_dimensions', 24))) {
                throw new InvalidArgumentException('Слишком много измерений кэша.');
            }

            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(fn (mixed $item): mixed => $this->canonicalValue($item, $count), $value);
        }

        if (is_string($value)) {
            $value = Str::squish(Str::lower($value));

            if (mb_strlen($value) > max(1, (int) config('cache-architecture.max_dimension_length', 160))) {
                throw new InvalidArgumentException('Измерение кэша превышает допустимую длину.');
            }

            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        throw new InvalidArgumentException('Неподдерживаемый тип измерения кэша.');
    }

    private function application(): string
    {
        return $this->boundedPrefix('application');
    }

    private function environment(): string
    {
        return $this->boundedPrefix('environment');
    }

    private function boundedPrefix(string $key): string
    {
        $value = Str::limit(Str::slug((string) config('cache-architecture.'.$key)), 32, '');

        if ($value === '') {
            throw new InvalidArgumentException("Префикс {$key} не настроен.");
        }

        return $value;
    }

    private function positiveConfig(string $key): int
    {
        $value = (int) config('cache-architecture.'.$key, 0);

        if ($value < 1) {
            throw new InvalidArgumentException("Версия {$key} должна быть положительной.");
        }

        return $value;
    }
}
