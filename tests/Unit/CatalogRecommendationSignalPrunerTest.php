<?php

namespace Tests\Unit;

use App\Models\CatalogTitle;
use App\Services\Catalog\CatalogRecommendationSignalPruner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogRecommendationSignalPrunerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prunes_only_allowlisted_seasonvar_generic_signals_in_bounded_chunks(): void
    {
        $title = CatalogTitle::factory()->create();
        $now = now();
        $genericRows = collect(range(1, 1_001))
            ->map(fn (int $index): array => [
                'catalog_title_id' => $title->id,
                'source' => 'seasonvar_info',
                'signal_type' => 'taxonomy_genre',
                'signal_key' => 'genre-'.$index,
                'signal_value' => 'Жанр '.$index,
                'weight' => 120,
                'observed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        foreach ($genericRows->chunk(500) as $chunk) {
            DB::table('catalog_title_recommendation_signals')->insert($chunk->all());
        }

        DB::table('catalog_title_recommendation_signals')->insert([
            $this->signalRow($title, 'seasonvar_info', 'rating', 'kinopoisk'),
            $this->signalRow($title, 'seasonvar_info', 'release_year', '2024'),
            $this->signalRow($title, 'seasonvar_info', 'page_quality', 'has_info_list'),
            $this->signalRow($title, 'manual', 'taxonomy_genre', 'manual-genre'),
            $this->signalRow($title, 'seasonvar_info', 'provider_recommendation', 'serial-777'),
            $this->signalRow($title, 'seasonvar_info', 'related_title', 'serial-888'),
            $this->signalRow($title, 'seasonvar_info', 'taxonomy', 'not-a-generic-prefix'),
        ]);
        $events = [];

        $result = app(CatalogRecommendationSignalPruner::class)->prune(
            function (string $event, array $context) use (&$events): void {
                $events[] = compact('event', 'context');
            },
        );

        $this->assertSame(1_004, $result['checked']);
        $this->assertSame(1_004, $result['deleted']);
        $this->assertSame(4, DB::table('catalog_title_recommendation_signals')->count());
        $this->assertDatabaseHas('catalog_title_recommendation_signals', [
            'source' => 'manual',
            'signal_type' => 'taxonomy_genre',
            'signal_key' => 'manual-genre',
        ]);
        $this->assertDatabaseHas('catalog_title_recommendation_signals', [
            'source' => 'seasonvar_info',
            'signal_type' => 'provider_recommendation',
            'signal_key' => 'serial-777',
        ]);
        $this->assertDatabaseHas('catalog_title_recommendation_signals', [
            'source' => 'seasonvar_info',
            'signal_type' => 'related_title',
            'signal_key' => 'serial-888',
        ]);
        $this->assertSame('catalog-recommendation-signals-pruned', $events[0]['event']);
        $this->assertSame($result, $events[0]['context']);
    }

    /** @return array<string, mixed> */
    private function signalRow(CatalogTitle $title, string $source, string $type, string $key): array
    {
        return [
            'catalog_title_id' => $title->id,
            'source' => $source,
            'signal_type' => $type,
            'signal_key' => $key,
            'signal_value' => null,
            'weight' => 100,
            'observed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
