<?php

namespace Tests\Feature;

use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarImportGroupKey;
use App\Services\Seasonvar\SeasonvarPageClaimManager;
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

    public function test_page_claim_is_atomic_owned_and_recoverable_after_expiry(): void
    {
        $run = $this->queuedRun();
        $page = SourcePage::factory()->create();
        $claims = app(SeasonvarPageClaimManager::class);

        $token = $claims->claim($page, $run->id, 60);

        $this->assertNotNull($token);
        $this->assertTrue($claims->owns($page->id, $run->id, $token));
        $this->assertNull($claims->claim($page, $run->id, 60));
        $this->assertFalse($claims->release($page->id, $run->id, 'wrong-token'));

        $page->update(['import_claim_expires_at' => now()->subSecond()]);

        $this->assertSame(1, $claims->recoverExpired());
        $this->assertSame(0, $claims->outstandingForRun($run->id));
        $this->assertNotNull($claims->claim($page->fresh(), $run->id, 60));
    }

    public function test_import_group_key_uses_external_id_and_hash_fallback(): void
    {
        $keys = app(SeasonvarImportGroupKey::class);

        $this->assertSame(
            'seasonvar-title:47915',
            $keys->forUrl('https://seasonvar.ru/serial-47915-Test-4-season.html', 'hash-a'),
        );
        $this->assertSame(
            'seasonvar-page:hash-b',
            $keys->forUrl('https://seasonvar.ru/catalog/test.html', 'hash-b'),
        );
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
