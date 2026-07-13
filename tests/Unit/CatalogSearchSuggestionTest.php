<?php

namespace Tests\Unit;

use App\Enums\CatalogSearchIndexStatus;
use App\Models\CatalogSearchIndexState;
use App\Models\CatalogTitle;
use App\Services\Catalog\Search\CatalogSearchIndexer;
use App\Services\Catalog\Search\CatalogSearchQueryParser;
use App\Services\Catalog\Search\CatalogSearchSuggestion;
use App\Services\Catalog\Search\CatalogTitleSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSearchSuggestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_typo_returns_a_bounded_similar_public_name(): void
    {
        $sherlock = CatalogTitle::factory()->create(['title' => 'Шерлок']);
        CatalogTitle::factory()->create(['title' => 'Совсем другое название']);
        app(CatalogSearchIndexer::class)->indexTitleIds(CatalogTitle::query()->pluck('id'));
        $this->markReady(2);

        $suggestions = app(CatalogSearchSuggestion::class)->forQuery(
            app(CatalogSearchQueryParser::class)->parse('шерлокк'),
        );

        $this->assertSame([$sherlock->id], $suggestions->pluck('id')->all());
        $this->assertGreaterThanOrEqual(0.55, $suggestions->first()->suggestion_similarity);
        $this->assertLessThanOrEqual(3, $suggestions->count());
    }

    public function test_existing_result_and_non_ready_index_never_return_suggestions(): void
    {
        $title = CatalogTitle::factory()->create(['title' => 'Шерлок']);
        app(CatalogSearchIndexer::class)->indexTitleIds([$title->id]);
        $this->markReady(1);
        $parser = app(CatalogSearchQueryParser::class);
        $suggestions = app(CatalogSearchSuggestion::class);

        $this->assertTrue($suggestions->forQuery($parser->parse('Шерлок'))->isEmpty());

        CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID)->update([
            'status' => CatalogSearchIndexStatus::Stale,
        ]);
        app(CatalogTitleSearch::class)->forgetState();

        $this->assertTrue(app(CatalogSearchSuggestion::class)->forQuery($parser->parse('шерлокк'))->isEmpty());
    }

    private function markReady(int $count): void
    {
        CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID)->update([
            'version' => CatalogSearchIndexer::INDEX_VERSION,
            'status' => CatalogSearchIndexStatus::Ready,
            'source_count' => $count,
            'document_count' => $count,
            'completed_at' => now(),
        ]);
    }
}
