<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicOutputTerminologyTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalog_responses_do_not_show_source_brand_or_card_term(): void
    {
        $sourceUrl = 'https://seasonvar.ru/serial-777-Public_hidden_source-1-season.html';
        $title = CatalogTitle::factory()->create([
            'slug' => 'public-output-test',
            'title' => 'Публичный тестовый сериал',
            'description' => 'Описание публичного тестового сериала.',
            'poster_url' => 'https://cdn.example.com/posters/public-output-test.jpg',
            'source_url' => $sourceUrl,
            'source_url_hash' => hash('sha256', $sourceUrl),
            'is_published' => true,
            'indexed_at' => now(),
        ]);

        $responses = [
            'home' => $this->get(route('home'))->assertOk()->getContent(),
            'titles.index' => $this->get(route('titles.index'))->assertOk()->getContent(),
            'titles.show' => $this->get(route('titles.show', $title))->assertOk()->getContent(),
            'stats' => $this->get(route('stats'))->assertOk()->getContent(),
            'api.titles.index' => $this->getJson('/api/titles')->assertOk()->getContent(),
            'api.titles.show' => $this->getJson('/api/titles/'.$title->slug)->assertOk()->getContent(),
            'feed' => $this->get(route('feed'))->assertOk()->assertStreamed()->streamedContent(),
            'opensearch' => $this->get(route('opensearch'))->assertOk()->assertStreamed()->streamedContent(),
            'llms' => $this->get(route('llms'))->assertOk()->assertStreamed()->streamedContent(),
        ];

        foreach ($responses as $name => $content) {
            $this->assertPublicOutputIsNeutral($content, $name);
        }
    }

    public function test_public_catalog_pages_do_not_show_system_status_messages(): void
    {
        $title = CatalogTitle::factory()->create([
            'slug' => 'public-status-test',
            'title' => 'Публичный сериал без служебных статусов',
            'description' => null,
            'is_published' => true,
            'indexed_at' => now(),
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'number' => 1,
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 1,
        ]);

        $responses = [
            'home' => $this->get(route('home'))->assertOk()->getContent(),
            'titles.index' => $this->get(route('titles.index'))->assertOk()->getContent(),
            'titles.show' => $this->get(route('titles.show', [
                'catalogTitle' => $title,
                'episode' => $episode->id,
            ]))->assertOk()->getContent(),
        ];

        foreach ($responses as $name => $content) {
            $visibleContent = $this->withoutHead($content);

            $this->assertStringNotContainsString('Статус готово', $visibleContent, $name);
            $this->assertStringNotContainsString('плеер готов', $visibleContent, $name);
            $this->assertStringNotContainsString('видео найдено', $visibleContent, $name);
            $this->assertStringNotContainsString('видео готовится', $visibleContent, $name);
            $this->assertStringNotContainsString('готовится', $visibleContent, $name);
            $this->assertStringNotContainsString('после обновления каталога', $visibleContent, $name);
            $this->assertStringNotContainsString('после ближайшего обновления', $visibleContent, $name);
            $this->assertStringNotContainsString('после обработки источника', $visibleContent, $name);
            $this->assertStringNotContainsString('Данные страницы', $visibleContent, $name);
            $this->assertStringNotContainsString('Последнее обновление', $visibleContent, $name);
            $this->assertStringNotContainsString('Индексация и обновления', $visibleContent, $name);
        }
    }

    public function test_public_title_page_deduplicates_generated_search_phrases(): void
    {
        $title = CatalogTitle::factory()->create([
            'slug' => 'public-generated-phrases-test',
            'title' => 'Публичный онлайн',
            'description' => 'Описание публичного сериала для проверки поисковых фраз.',
            'is_published' => true,
            'indexed_at' => now(),
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'number' => 1,
        ]);
        Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 1,
        ]);

        $content = $this->get(route('titles.show', $title))
            ->assertOk()
            ->getContent();

        $this->assertPublicOutputIsNeutral($content, 'titles.show.generated_phrases');
    }

    private function assertPublicOutputIsNeutral(string $content, string $name): void
    {
        $content = $this->removeAllowedHosts($content);

        $this->assertDoesNotMatchRegularExpression('/Карточк|карточк/u', $content, $name);
        $this->assertDoesNotMatchRegularExpression('/тайтл/iu', $content, $name);
        $this->assertDoesNotMatchRegularExpression('/seasonvar|сезонвар/iu', $content, $name);
        $this->assertDoesNotMatchRegularExpression('/смотреть онлайн\s+смотреть онлайн/iu', $content, $name);
        $this->assertDoesNotMatchRegularExpression('/онлайн\s+онлайн/iu', $content, $name);
        $this->assertDoesNotMatchRegularExpression('/онлайн\s+(?:смотреть|сериал)\s+онлайн/iu', $content, $name);
        $this->assertDoesNotMatchRegularExpression('/смотреть онлайн\s+сериал онлайн/iu', $content, $name);
        $this->assertDoesNotMatchRegularExpression('/смотреть в хорошем качестве\s+смотреть в хорошем качестве/iu', $content, $name);
        $this->assertDoesNotMatchRegularExpression('/в хорошем качестве\s+хорошее качество/iu', $content, $name);
        $this->assertDoesNotMatchRegularExpression('/(?:все сезоны|все серии)\s+сезоны и серии/iu', $content, $name);
        $this->assertDoesNotMatchRegularExpression('/все сезоны\s+все серии/iu', $content, $name);
        $this->assertDoesNotMatchRegularExpression('/веб[- ]плеер\s+веб[- ]плеер/iu', $content, $name);
        $this->assertStringNotContainsString('js-seasonvar-player', $content, $name);
        $this->assertStringNotContainsString('source_url', $content, $name);
    }

    private function removeAllowedHosts(string $content): string
    {
        foreach ($this->allowedHosts() as $host) {
            $content = str_ireplace($host, '', $content);
        }

        return $content;
    }

    private function withoutHead(string $content): string
    {
        return (string) preg_replace('/<head\b[^>]*>.*?<\/head>/isu', '', $content);
    }

    /**
     * @return list<string>
     */
    private function allowedHosts(): array
    {
        return collect([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            'seasonvar.miniserver.fun',
            'localhost',
            '127.0.0.1',
        ])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
