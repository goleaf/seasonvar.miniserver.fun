<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Collections\Import\HdRezkaCollectionParser;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use UnexpectedValueException;

final class HdRezkaCollectionParserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_parser_returns_normalized_collection_definitions(): void
    {
        $collections = app(HdRezkaCollectionParser::class)->collections($this->fixture('collections-index.html'));

        $this->assertCount(2, $collections);
        $this->assertSame('Про любовь', $collections[0]->name);
        $this->assertSame(
            '/xfsearch/collections/%D0%A4%D0%B8%D0%BB%D1%8C%D0%BC%D1%8B%20%D0%BF%D1%80%D0%BE%20%D0%BB%D1%8E%D0%B1%D0%BE%D0%B2%D1%8C/',
            $collections[0]->path,
        );
        $this->assertSame('/uploads/mini/14/b1/c7cf54f9c99caa7918118d120a9987.jpg', $collections[0]->coverPath);
        $this->assertSame(1, $collections[0]->position);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $collections[0]->sourceKey);
        $this->assertNotSame($collections[0]->sourceKey, $collections[1]->sourceKey);
    }

    public function test_parser_returns_stable_items_and_the_next_page(): void
    {
        $result = app(HdRezkaCollectionParser::class)->page(
            $this->fixture('collection-page-1.html'),
            '/xfsearch/collections/films/',
            1,
        );

        $this->assertCount(2, $result['items']);
        $this->assertSame('668', $result['items'][0]->sourceItemKey);
        $this->assertSame('Муфаса: Король Лев', $result['items'][0]->title);
        $this->assertSame('муфаса король лев', $result['items'][0]->normalizedTitleKey);
        $this->assertSame(2024, $result['items'][0]->year);
        $this->assertSame('cartoon', $result['items'][0]->type);
        $this->assertSame(['сша', 'канада'], $result['items'][0]->countries);
        $this->assertSame('/668-mufasa-the-lion-king.html', $result['items'][0]->detailPath);
        $this->assertSame(1, $result['items'][0]->page);
        $this->assertSame(1, $result['items'][0]->position);
        $this->assertSame('/xfsearch/collections/films/page/2/', $result['next_path']);
    }

    public function test_last_page_has_no_next_path(): void
    {
        $result = app(HdRezkaCollectionParser::class)->page(
            $this->fixture('collection-page-2.html'),
            '/xfsearch/collections/films/',
            2,
        );

        $this->assertSame('series', $result['items'][0]->type);
        $this->assertNull($result['next_path']);
    }

    public function test_parser_rejects_a_pagination_gap_instead_of_treating_the_snapshot_as_complete(): void
    {
        $this->expectException(UnexpectedValueException::class);

        app(HdRezkaCollectionParser::class)->page(<<<'HTML'
            <div id="dle-content">
                <div class="card_item">
                    <a class="card_item__title" href="/668-mufasa.html">Муфаса</a>
                    <div class="card_item__misc">2024, США</div>
                </div>
                <div class="pagination">
                    <span>1</span>
                    <a href="/xfsearch/collections/films/page/3/">3</a>
                </div>
            </div>
            HTML, '/xfsearch/collections/films/', 1);
    }

    public function test_detail_parser_uses_the_title_json_ld_object(): void
    {
        $detail = app(HdRezkaCollectionParser::class)->detail($this->fixture('title-detail.html'));

        $this->assertSame('Mufasa: The Lion King', $detail['original_title']);
        $this->assertSame(2024, $detail['year']);
        $this->assertSame('film', $detail['type']);
        $this->assertSame(['мультфильм', 'мюзикл', 'драма'], $detail['genres']);
    }

    public function test_parser_rejects_a_card_without_a_stable_numeric_detail_id(): void
    {
        $this->expectException(UnexpectedValueException::class);

        app(HdRezkaCollectionParser::class)->page(<<<'HTML'
            <div id="dle-content">
                <div class="card_item">
                    <a class="card_item__title" href="/missing-id.html">Без идентификатора</a>
                    <div class="card_item__misc">2024, США</div>
                </div>
            </div>
            HTML, '/xfsearch/collections/films/', 1);
    }

    public function test_parser_keeps_a_numeric_source_id_when_the_remote_slug_is_empty(): void
    {
        $parsed = app(HdRezkaCollectionParser::class)->page(<<<'HTML'
            <div id="dle-content">
                <div class="card_item">
                    <a class="card_item__title" href="/24053-.html">Без slug</a>
                    <div class="card_item__misc">2024, США</div>
                </div>
            </div>
            HTML, '/xfsearch/collections/films/', 1);

        $this->assertSame('24053', $parsed['items'][0]->sourceItemKey);
        $this->assertSame('/24053-.html', $parsed['items'][0]->detailPath);
    }

    public function test_parser_rejects_invalid_utf8(): void
    {
        $this->expectException(UnexpectedValueException::class);

        app(HdRezkaCollectionParser::class)->collections("\xFF");
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(base_path("tests/Fixtures/hdrezka/{$name}"));

        $this->assertIsString($contents);

        return $contents;
    }
}
