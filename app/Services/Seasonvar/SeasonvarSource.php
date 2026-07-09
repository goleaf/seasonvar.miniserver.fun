<?php

namespace App\Services\Seasonvar;

use App\Models\Source;

class SeasonvarSource
{
    public function current(): Source
    {
        return Source::query()->updateOrCreate(
            ['code' => 'seasonvar'],
            [
                'name' => 'Метаданные Seasonvar',
                'base_url' => $this->baseUrl(),
                'is_active' => true,
                'crawl_delay_seconds' => $this->crawlDelaySeconds(),
                'settings' => [
                    'scope' => 'только публичные метаданные каталога seasonvar.ru',
                    'sitemap_url' => $this->sitemapUrl(),
                    'blocked' => ['плеер', 'плейлист', 'сеть доставки', 'видеопотоки'],
                ],
            ],
        );
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('seasonvar.base_url', 'https://seasonvar.ru'), '/');
    }

    public function sitemapUrl(): string
    {
        return (string) config('seasonvar.sitemap_url', $this->baseUrl().'/sitemap_index.xml');
    }

    public function crawlDelaySeconds(): int
    {
        return max(0, (int) config('seasonvar.crawl_delay_seconds', 3));
    }
}
