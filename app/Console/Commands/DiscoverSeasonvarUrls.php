<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarDiscovery;
use App\Services\Seasonvar\SeasonvarUrl;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('seasonvar:discover {sitemap=https://seasonvar.net/sitemap_index.xml : Sitemap URL} {--limit=500 : Maximum URLs to store} {--dry-run : Show discovered URLs without storing them}')]
#[Description('Discover allowed Seasonvar catalog URLs from a sitemap')]
class DiscoverSeasonvarUrls extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SeasonvarDiscovery $discovery, SeasonvarUrl $seasonvarUrl): int
    {
        $source = Source::query()->firstOrCreate(
            ['code' => 'seasonvar'],
            [
                'name' => 'Seasonvar Metadata',
                'base_url' => 'https://seasonvar.net',
                'is_active' => true,
                'crawl_delay_seconds' => 3,
            ],
        );

        $sitemap = (string) $this->argument('sitemap');
        $limit = max(1, (int) $this->option('limit'));
        $urls = $discovery->discoverFromSitemap($sitemap, $limit, (int) $source->crawl_delay_seconds);

        if ((bool) $this->option('dry-run')) {
            foreach ($urls as $url) {
                $this->line($url);
            }

            $this->info('Dry run complete: '.count($urls).' URLs discovered.');

            return self::SUCCESS;
        }

        foreach ($urls as $url) {
            SourcePage::query()->updateOrCreate(
                ['url_hash' => $seasonvarUrl->hash($url)],
                [
                    'source_id' => $source->id,
                    'url' => $url,
                    'page_type' => $seasonvarUrl->pageType($url),
                    'parse_status' => 'pending',
                    'discovered_from_url' => $sitemap,
                ],
            );
        }

        $this->info('Stored '.count($urls).' discovered URLs.');

        return self::SUCCESS;
    }
}
