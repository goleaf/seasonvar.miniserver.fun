<?php

namespace Tests\Feature;

use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeasonvarParallelImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_parallel_import_schema_and_defaults_are_available(): void
    {
        $page = SourcePage::factory()->create([
            'import_claim_token' => 'claim-token',
            'import_claimed_at' => now(),
            'import_claim_expires_at' => now()->addHour(),
        ]);
        $run = SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $page->update(['import_claim_run_id' => $run->id]);

        $freshPage = $page->fresh();

        $this->assertSame('claim-token', $freshPage->import_claim_token);
        $this->assertTrue($freshPage->import_claimed_at->equalTo($page->import_claimed_at));
        $this->assertTrue($freshPage->import_claim_expires_at->equalTo($page->import_claim_expires_at));
        $this->assertTrue($freshPage->importClaimRun->is($run));
        $this->assertSame('queue', $run->fresh()->execution_mode);
        $this->assertSame('redis', config('seasonvar.queue.connection'));
        $this->assertSame('seasonvar-import', config('seasonvar.queue.queue'));
        $this->assertSame('redis', config('seasonvar.queue.lock_store'));
        $this->assertSame(86400, config('seasonvar.queue.claim_seconds'));
        $this->assertSame(24, config('seasonvar.import.refresh_after_hours'));
    }
}
