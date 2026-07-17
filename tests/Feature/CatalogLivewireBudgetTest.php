<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\CatalogTitleDetail;
use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Drawer\Utils;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogLivewireBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const CATALOG_INITIAL_MAX_BYTES = 230_000;

    private const CATALOG_DEFERRED_MAX_BYTES = 285_000;

    private const CATALOG_UPDATE_MAX_BYTES = 285_000;

    private const TITLE_INITIAL_MAX_BYTES = 100_000;

    private const TITLE_UPDATE_MAX_BYTES = 95_000;

    private const CATALOG_INITIAL_MAX_QUERIES = 8;

    private const CATALOG_DEFERRED_MAX_QUERIES = 12;

    private const CATALOG_UPDATE_MAX_QUERIES = 8;

    private const TITLE_INITIAL_MAX_QUERIES = 29;

    private const TITLE_UPDATE_MAX_QUERIES = 23;

    public function test_catalog_initial_deferred_and_update_responses_stay_inside_bounded_budgets(): void
    {
        $sourceUrl = 'https://seasonvar.ru/serial-99001-Budget-secret-1-season.html';

        foreach (range(1, 24) as $number) {
            $title = CatalogTitle::factory()->create([
                'title' => sprintf('Бюджетный сериал %02d', $number),
                'slug' => sprintf('biudzhetnyi-serial-%02d', $number),
                'year' => 2024,
                'description' => str_repeat('Описание карточки. ', 5),
                ...($number === 1 ? [
                    'source_url' => $sourceUrl,
                    'source_url_hash' => hash('sha256', $sourceUrl),
                ] : []),
            ]);
            $actor = Actor::query()->create([
                'name' => sprintf('Актер бюджета %02d', $number),
                'slug' => sprintf('akter-biudzheta-%02d', $number),
            ]);
            $title->actors()->attach($actor);
        }

        [$initial, $initialQueries] = $this->captureQueries(
            fn (): TestResponse => $this->get(route('titles.index'))->assertOk(),
        );
        $initialContent = $initial->getContent();
        $snapshot = $this->componentSnapshot($initialContent, 'catalog-series');

        $this->assertLessThanOrEqual(
            self::CATALOG_INITIAL_MAX_BYTES,
            strlen($initialContent),
            'Начальный catalog response превысил byte budget.',
        );
        $this->assertLessThanOrEqual(self::CATALOG_INITIAL_MAX_QUERIES, $initialQueries);
        $this->assertStringNotContainsString($sourceUrl, $initialContent);
        $this->assertStringNotContainsString('App\\Models\\CatalogTitle', $initialContent);

        [$deferred, $deferredQueries] = $this->captureQueries(
            fn (): TestResponse => $this->livewireUpdate($snapshot, '__lazyLoadIsland'),
        );
        $deferred->assertOk();
        $deferredContent = $deferred->getContent();
        $deferredSnapshot = data_get($deferred->json(), 'components.0.snapshot');

        $this->assertIsString($deferredSnapshot);
        $this->assertLessThanOrEqual(
            self::CATALOG_DEFERRED_MAX_BYTES,
            strlen($deferredContent),
            'Deferred catalog response превысил byte budget.',
        );
        $this->assertLessThanOrEqual(self::CATALOG_DEFERRED_MAX_QUERIES, $deferredQueries);
        $this->assertStringNotContainsString($sourceUrl, $deferredContent);
        $this->assertStringNotContainsString('App\\Models\\CatalogTitle', $deferredContent);

        [$update, $updateQueries] = $this->captureQueries(
            fn (): TestResponse => $this->livewireUpdate(
                json_decode($deferredSnapshot, true, flags: JSON_THROW_ON_ERROR),
                'sortBy',
                ['title_asc'],
            ),
        );
        $update->assertOk();
        $updateContent = $update->getContent();

        $this->assertLessThanOrEqual(
            self::CATALOG_UPDATE_MAX_BYTES,
            strlen($updateContent),
            'Catalog update response превысил byte budget.',
        );
        $this->assertLessThanOrEqual(self::CATALOG_UPDATE_MAX_QUERIES, $updateQueries);
        $this->assertStringNotContainsString($sourceUrl, $updateContent);
        $this->assertStringNotContainsString('App\\Models\\CatalogTitle', $updateContent);
    }

    public function test_title_shell_and_repeated_update_stay_bounded_as_public_recommendations_grow(): void
    {
        config(['seasonvar.recommendations.max_per_title' => 12]);
        $sourceUrl = 'https://seasonvar.ru/serial-99002-Title-budget-secret-1-season.html';
        $source = CatalogTitle::factory()->create([
            'title' => 'Сериал с бюджетом оболочки',
            'source_url' => $sourceUrl,
            'source_url_hash' => hash('sha256', $sourceUrl),
        ]);

        foreach (range(1, 12) as $rank) {
            $recommended = CatalogTitle::factory()->create([
                'title' => sprintf('Открытая рекомендация %02d', $rank),
                'slug' => sprintf('otkrytaia-rekomendatsiia-%02d', $rank),
            ]);
            CatalogTitleRecommendation::query()->create([
                'catalog_title_id' => $source->id,
                'recommended_title_id' => $recommended->id,
                'score' => 1000 - $rank,
                'rank' => $rank,
                'algorithm_version' => 'budget-v1',
                'reasons' => ['genre' => ['count' => 1, 'score' => 100]],
                'computed_at' => now(),
            ]);
        }

        [$component, $initialQueries] = $this->captureQueries(
            fn (): Testable => Livewire::test(CatalogTitleDetail::class, ['catalogTitle' => $source]),
        );
        $initialContent = $this->livewireResponseContent($component);

        $this->assertLessThanOrEqual(
            self::TITLE_INITIAL_MAX_BYTES,
            strlen($initialContent),
            'Начальный title shell превысил byte budget.',
        );
        $this->assertLessThanOrEqual(self::TITLE_INITIAL_MAX_QUERIES, $initialQueries);
        $this->assertStringNotContainsString($sourceUrl, $initialContent);
        $this->assertStringNotContainsString('App\\Models\\CatalogTitle', $initialContent);

        [$updatedComponent, $updateQueries] = $this->captureQueries(
            fn (): Testable => $component->call('refreshCatalog'),
        );
        $updateContent = $this->livewireResponseContent($updatedComponent);

        $this->assertLessThanOrEqual(
            self::TITLE_UPDATE_MAX_BYTES,
            strlen($updateContent),
            'Повторный title update превысил byte budget.',
        );
        $this->assertLessThanOrEqual(self::TITLE_UPDATE_MAX_QUERIES, $updateQueries);
        $this->assertStringNotContainsString($sourceUrl, $updateContent);
        $this->assertStringNotContainsString('App\\Models\\CatalogTitle', $updateContent);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, mixed>  $params
     */
    private function livewireUpdate(array $snapshot, string $method, array $params = []): TestResponse
    {
        return $this
            ->withHeader('X-Livewire', 'true')
            ->postJson(app('livewire')->getUpdateUri(), [
                'components' => [[
                    'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'updates' => [],
                    'calls' => [[
                        'path' => '',
                        'method' => $method,
                        'params' => $params,
                        'metadata' => [
                            'island' => [
                                'name' => 'catalog-live',
                                'mode' => 'morph',
                            ],
                        ],
                    ]],
                ]],
            ]);
    }

    private function livewireResponseContent(Testable $component): string
    {
        $reflection = new \ReflectionClass($component);
        $state = $reflection->getProperty('lastState')->getValue($component);

        return (string) $state->getResponse()->getContent();
    }

    /** @return array<string, mixed> */
    private function componentSnapshot(string $html, string $name): array
    {
        preg_match('/<[a-z][^>]*\bwire:name="'.preg_quote($name, '/').'"[^>]*>/is', $html, $matches);
        $this->assertNotEmpty($matches, "Livewire component {$name} was not found in the response.");

        return Utils::extractAttributeDataFromHtml($matches[0], 'wire:snapshot');
    }

    /**
     * @template TValue
     *
     * @param  callable(): TValue  $callback
     * @return array{TValue, int}
     */
    private function captureQueries(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $result = $callback();

            return [$result, count(DB::getQueryLog())];
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
