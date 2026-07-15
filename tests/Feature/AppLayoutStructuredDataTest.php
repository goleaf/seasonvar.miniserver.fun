<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AppLayoutStructuredDataTest extends TestCase
{
    private const TEST_PATH = '/_testing/layout-structured-data';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get(self::TEST_PATH, static fn () => view('layouts.app', [
            'seo' => [
                'title' => 'Проверка структурированных данных',
                'description' => 'Безопасный JSON-LD без вычислений в Blade.',
                'canonical' => url(self::TEST_PATH),
                'topic_terms' => ['недостижимая тема'],
                'search_phrases' => ['недостижимый запрос'],
                'jsonLd' => [
                    '@context' => 'https://schema.org',
                    '@type' => 'WebPage',
                    'name' => 'Проверка структурированных данных',
                    'url' => 'https://example.test/</script><script>alert(1)</script>',
                ],
            ],
        ]));
    }

    public function test_layout_renders_prepared_hex_safe_json_ld(): void
    {
        $content = $this->get(self::TEST_PATH)->assertOk()->getContent();

        $this->assertSame(1, preg_match_all(
            '~<script type="application/ld\+json">(.*?)</script>~s',
            $content,
            $matches,
        ));
        $this->assertStringNotContainsString('</script><script>', $matches[1][0]);
        $this->assertStringContainsString('\\u003C/script\\u003E', $matches[1][0]);
        $this->assertSame(
            'https://example.test/</script><script>alert(1)</script>',
            json_decode($matches[1][0], true, 512, JSON_THROW_ON_ERROR)['url'],
        );
    }

    public function test_layout_does_not_render_inactive_generated_seo_matrix(): void
    {
        $response = $this->get(self::TEST_PATH)->assertOk();

        $response
            ->assertDontSee('<meta name="answer-count"', false)
            ->assertDontSee('<meta name="query-matrix-count"', false)
            ->assertDontSee('id="semantic-hubs"', false)
            ->assertDontSee('id="popular-searches"', false);
    }
}
