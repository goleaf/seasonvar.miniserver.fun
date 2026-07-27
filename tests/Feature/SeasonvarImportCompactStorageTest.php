<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SeasonvarImportPreparedPage;
use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;
use App\Models\SourcePage;
use App\Models\SourcePageSnapshot;
use App\Services\Seasonvar\SeasonvarImportPayloadCodec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeasonvarImportCompactStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_compact_storage_schema_is_available(): void
    {
        $this->assertTrue(Schema::hasColumns(
            'seasonvar_import_prepared_pages',
            [
                'payload_blob',
                'payload_codec',
                'payload_uncompressed_bytes',
                'application_result',
            ],
        ));
        $this->assertTrue(Schema::hasColumns(
            'source_page_snapshots',
            [
                'html_blob',
                'html_codec',
                'html_uncompressed_bytes',
            ],
        ));
    }

    public function test_compact_prepared_payload_uses_dual_reader_and_applied_checkpoint_does_not_rewrite_blob(): void
    {
        config(['seasonvar.import.compact_storage_write_enabled' => true]);
        $row = $this->preparedRow();
        $payload = [
            'source_page_id' => $row->source_page_id,
            'catalog_data' => ['title' => 'Рыжая'],
            'discovered_season_urls' => [],
            'content_hash' => hash('sha256', 'prepared'),
            'parser_version' => 1,
            'warnings' => [],
            'large' => str_repeat('повторяемые данные ', 1000),
        ];

        $row->markPrepared(
            $payload,
            [],
            hash('sha256', 'prepared'),
            1,
        );
        $fresh = $row->fresh();
        $blob = $fresh->payload_blob;

        $this->assertNull($fresh->payload);
        $this->assertSame('gzip-json-v1', $fresh->payload_codec);
        $this->assertSame($payload, $fresh->decodedPayload());
        $this->assertIsString($blob);
        $this->assertLessThan(
            $fresh->payload_uncompressed_bytes,
            mb_strlen($blob, '8bit'),
        );

        $fresh->markApplied([
            'media_attached' => 1,
            'media_updated' => 2,
            'media_skipped' => 3,
            'media_failed' => 4,
        ]);

        $this->assertSame($blob, $fresh->fresh()->payload_blob);
        $this->assertSame([
            'media_attached' => 1,
            'media_updated' => 2,
            'media_skipped' => 3,
            'media_failed' => 4,
        ], $fresh->fresh()->applicationResult());
    }

    public function test_legacy_prepared_payload_and_compact_snapshot_remain_readable(): void
    {
        $row = $this->preparedRow();
        $legacy = ['source_page_id' => $row->source_page_id, 'legacy' => true];
        $row->update(['payload' => $legacy]);
        $codec = app(SeasonvarImportPayloadCodec::class);
        $encoded = $codec->encodeString('<html><h1>Снимок</h1></html>');
        $snapshot = SourcePageSnapshot::query()->create([
            'source_page_id' => $row->source_page_id,
            'seasonvar_import_run_id' => $row->seasonvar_import_run_id,
            'url' => $row->sourcePage->url,
            'content_hash' => hash('sha256', '<html><h1>Снимок</h1></html>'),
            'http_status' => 200,
            'body_bytes' => 32,
            'html' => 'legacy fallback',
            'html_blob' => $encoded['blob'],
            'html_codec' => $encoded['codec'],
            'html_uncompressed_bytes' => $encoded['uncompressed_bytes'],
            'captured_at' => now(),
        ]);

        $this->assertSame($legacy, $row->fresh()->decodedPayload());
        $this->assertSame(
            '<html><h1>Снимок</h1></html>',
            $snapshot->fresh()->body(),
        );
    }

    private function preparedRow(): SeasonvarImportPreparedPage
    {
        $run = SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'running',
            'started_at' => now(),
        ]);
        $group = SeasonvarImportTitleGroup::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'group_key_hash' => hash('sha256', 'compact-storage'),
            'queue_name' => 'seasonvar-import',
            'status' => 'running',
            'started_at' => now(),
        ]);
        $page = SourcePage::factory()->create(['page_type' => 'serial']);

        return SeasonvarImportPreparedPage::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'seasonvar_import_title_group_id' => $group->id,
            'source_page_id' => $page->id,
            'status' => 'queued',
            'warnings' => [],
        ])->load('sourcePage');
    }
}
