<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Seasonvar\RecordSeasonvarPageFailure;
use App\Enums\SeasonvarImportFailureType;
use App\Exceptions\Seasonvar\SeasonvarSourceRequestException;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Tests\TestCase;

class SeasonvarPageFailureActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_a_permanent_gone_page_once(): void
    {
        $this->assertTrue(class_exists(RecordSeasonvarPageFailure::class));

        $run = $this->queuedRun();
        $page = SourcePage::factory()->create(['failure_count' => 2]);

        $type = app(RecordSeasonvarPageFailure::class)->handle(
            $page,
            SeasonvarSourceRequestException::forStatus(404),
            $run->id,
        );

        $page->refresh();

        $this->assertSame(SeasonvarImportFailureType::Permanent, $type);
        $this->assertSame('failed', $page->parse_status);
        $this->assertSame('gone', $page->import_status);
        $this->assertSame(404, $page->http_status);
        $this->assertSame(3, $page->failure_count);
        $this->assertSame($run->id, $page->last_import_run_id);
        $this->assertSame('Seasonvar вернул HTTP 404.', $page->error_message);
        $this->assertTrue($page->retry_after_at->between(now()->addDays(7)->subSecond(), now()->addDays(7)->addSecond()));
    }

    public function test_it_records_a_transient_failure_with_bounded_backoff(): void
    {
        $this->assertTrue(class_exists(RecordSeasonvarPageFailure::class));

        $run = $this->queuedRun();
        $page = SourcePage::factory()->create(['failure_count' => 0]);

        $type = app(RecordSeasonvarPageFailure::class)->handle(
            $page,
            new ConnectionException('Connection timed out'),
            $run->id,
        );

        $page->refresh();

        $this->assertSame(SeasonvarImportFailureType::Transient, $type);
        $this->assertSame('failed', $page->parse_status);
        $this->assertSame('failed', $page->import_status);
        $this->assertSame(200, $page->http_status);
        $this->assertSame(1, $page->failure_count);
        $this->assertSame($run->id, $page->last_import_run_id);
        $this->assertSame('Connection timed out', $page->error_message);
        $this->assertTrue($page->retry_after_at->between(now()->addMinutes(15)->subSecond(), now()->addMinutes(15)->addSecond()));
    }

    private function queuedRun(): SeasonvarImportRun
    {
        return SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'running',
            'started_at' => now(),
        ]);
    }
}
