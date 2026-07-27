<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\Seasonvar\SeasonvarCatalogData;
use App\DTOs\Seasonvar\SeasonvarPreparedCatalogPage;
use App\Services\Seasonvar\SeasonvarCatalogParser;
use Tests\TestCase;

final class SeasonvarPreparedPayloadFingerprintTest extends TestCase
{
    public function test_semantically_equivalent_markup_and_list_permutations_have_one_fingerprint(): void
    {
        $parser = app(SeasonvarCatalogParser::class);
        $html = $this->fixture('complete-serial.html');
        $first = SeasonvarCatalogData::fromParsed($parser->parse(
            $html,
            'https://seasonvar.ru/serial-61000-Exact-2-season.html',
        ));
        $noisyHtml = str_replace(
            ['<h1 class="pgs-sinfo-title">', '<div class="pgs-sinfo_list">'],
            ['<h1 data-noise="ignored" class="pgs-sinfo-title">', "<div\n class=\"pgs-sinfo_list\">"],
            $html,
        );
        $secondArray = $parser->parse(
            $noisyHtml,
            'https://seasonvar.ru/serial-61000-Exact-2-season.html',
        );
        $secondArray['taxonomies'] = array_reverse($secondArray['taxonomies']);
        $secondArray['aliases'] = array_reverse($secondArray['aliases']);
        $second = SeasonvarCatalogData::fromParsed($secondArray);

        $this->assertSame($first->semanticFingerprint(), $second->semanticFingerprint());
    }

    public function test_prepared_payload_round_trip_preserves_fingerprint_and_supports_legacy_payload(): void
    {
        $data = SeasonvarCatalogData::fromParsed(app(SeasonvarCatalogParser::class)->parse(
            $this->fixture('complete-serial.html'),
            'https://seasonvar.ru/serial-61000-Exact-2-season.html',
        ));
        $prepared = new SeasonvarPreparedCatalogPage(
            sourcePageId: 10,
            catalogData: $data,
            discoveredSeasonUrls: [],
            contentHash: hash('sha256', 'fixture'),
            parserVersion: SeasonvarCatalogParser::METADATA_VERSION,
        );
        $payload = $prepared->toPayload();

        $this->assertSame($data->semanticFingerprint(), $payload['semantic_fingerprint']);
        $this->assertSame(
            $prepared->semanticFingerprint,
            SeasonvarPreparedCatalogPage::fromPayload($payload)->semanticFingerprint,
        );

        unset($payload['semantic_fingerprint']);

        $this->assertSame(
            $data->semanticFingerprint(),
            SeasonvarPreparedCatalogPage::fromPayload($payload)->semanticFingerprint,
        );
    }

    public function test_generated_2600_episode_fixture_is_bounded_and_deterministic(): void
    {
        $definition = json_decode($this->fixture('large-episodes.json'), true, flags: JSON_THROW_ON_ERROR);
        $episodes = [];

        for ($number = 1; $number <= $definition['episodes']; $number++) {
            $episodes[$number.'_seriya'] = ['n' => (string) $number];
        }

        $html = sprintf(
            '<html><head><title>%s смотреть онлайн</title></head><body><h1>%s</h1><script>var arEpisodes = %s;</script></body></html>',
            $definition['title'],
            $definition['title'],
            json_encode([$episodes], JSON_THROW_ON_ERROR),
        );
        $before = memory_get_usage(true);
        $first = SeasonvarCatalogData::fromParsed(app(SeasonvarCatalogParser::class)->parse(
            $html,
            sprintf('https://seasonvar.ru/serial-%d-Large-1-season.html', $definition['external_id']),
        ));
        $memoryDelta = max(0, memory_get_peak_usage(true) - $before);
        $second = SeasonvarCatalogData::fromParsed(app(SeasonvarCatalogParser::class)->parse(
            $html,
            sprintf('https://seasonvar.ru/serial-%d-Large-1-season.html', $definition['external_id']),
        ));

        $this->assertCount(2600, $first->episodes);
        $this->assertSame($first->semanticFingerprint(), $second->semanticFingerprint());
        $this->assertLessThanOrEqual($definition['memory_budget_bytes'], $memoryDelta);
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(base_path('tests/Fixtures/seasonvar/'.$name));
        $this->assertIsString($contents);

        return $contents;
    }
}
