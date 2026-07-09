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
                'name' => 'Каталог Seasonvar',
                'base_url' => $this->baseUrl(),
                'is_active' => true,
                'crawl_delay_seconds' => $this->crawlDelaySeconds(),
                'settings' => [
                    'scope' => 'публичные страницы каталога, сезоны, серии, отзывы и внешние видео-ссылки seasonvar.ru',
                    'sitemap_url' => $this->sitemapUrl(),
                    'stores' => ['metadata', 'relations', 'reviews', 'external_video_links'],
                    'downloads_video_files' => false,
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
