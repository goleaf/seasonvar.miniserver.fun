<?php

namespace App\Console\Commands;

use App\Models\Source;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('seasonvar:seed-source')]
#[Description('Create or update the Seasonvar metadata source configuration')]
class SeedSeasonvarSource extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $source = Source::query()->updateOrCreate(
            ['code' => 'seasonvar'],
            [
                'name' => 'Seasonvar Metadata',
                'base_url' => env('SEASONVAR_BASE_URL', 'https://seasonvar.net'),
                'is_active' => true,
                'crawl_delay_seconds' => (int) env('SEASONVAR_CRAWL_DELAY', 3),
                'settings' => [
                    'scope' => 'public catalog metadata only',
                    'blocked' => ['player', 'playlist', 'cdn', 'video streams'],
                ],
            ],
        );

        $this->info("Seasonvar source ready: {$source->base_url}");

        return self::SUCCESS;
    }
}
