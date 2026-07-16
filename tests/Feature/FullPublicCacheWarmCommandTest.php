<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\WarmCatalogCaches;
use App\Jobs\WarmPublicCatalogCaches;
use App\Services\Catalog\PublicCatalogWarmStateStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class FullPublicCacheWarmCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_public_requires_queue_unless_dry_run(): void
    {
        $this->artisan('cache:warm-catalog', ['--scope' => 'all-public'])
            ->expectsOutput('Полный публичный прогрев выполняется только через Redis-очередь. Добавьте --queue.')
            ->assertFailed();
    }

    public function test_critical_scope_keeps_existing_queue_contract(): void
    {
        Queue::fake();

        $this->artisan('cache:warm-catalog', ['--queue' => true])
            ->expectsOutput('Прогрев поставлен в очередь cache-warm-v2.')
            ->assertSuccessful();

        Queue::assertPushed(WarmCatalogCaches::class);
        Queue::assertNotPushed(WarmPublicCatalogCaches::class);
    }

    public function test_unknown_scope_is_rejected(): void
    {
        $this->artisan('cache:warm-catalog', ['--scope' => 'private'])
            ->expectsOutput('Область прогрева должна быть critical или all-public.')
            ->assertFailed();
    }

    public function test_resume_is_rejected_for_critical_scope(): void
    {
        $this->artisan('cache:warm-catalog', ['--resume' => true])
            ->expectsOutput('Параметр --resume доступен только для --scope=all-public.')
            ->assertFailed();
    }

    public function test_all_public_queue_starts_a_generation(): void
    {
        Queue::fake();

        $this->artisan('cache:warm-catalog', ['--scope' => 'all-public', '--queue' => true])
            ->expectsOutputToContain('Полный публичный прогрев поставлен в очередь cache-warm-v2.')
            ->assertSuccessful();

        $state = app(PublicCatalogWarmStateStore::class)->read();
        $this->assertSame('queued', $state['status'] ?? null);
        $this->assertGreaterThan(0, $state['estimated'] ?? 0);
        Queue::assertPushed(WarmPublicCatalogCaches::class, 1);
    }

    public function test_all_public_dry_run_does_not_dispatch_or_send_http(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();

        $this->artisan('cache:warm-catalog', ['--scope' => 'all-public', '--dry-run' => true])
            ->expectsOutputToContain('Безопасных публичных целей:')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertNull(app(PublicCatalogWarmStateStore::class)->read());
    }

    public function test_resume_requires_an_unfinished_generation(): void
    {
        Queue::fake();

        $this->artisan('cache:warm-catalog', [
            '--scope' => 'all-public',
            '--queue' => true,
            '--resume' => true,
        ])->expectsOutput('Нет незавершённого поколения полного публичного прогрева.')
            ->assertFailed();

        Queue::assertNothingPushed();
    }
}
