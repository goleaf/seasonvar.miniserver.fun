<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\WarmUserPortalCache;
use App\Models\User;
use App\Services\UserPortal\UserPortalCache;
use App\Services\UserPortal\UserPortalCacheInvalidator;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class UserPortalCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'cache-architecture.stores.hot' => 'array',
            'cache-architecture.stores.domain' => 'array',
            'cache-architecture.stores.locks' => 'array',
            'cache-architecture.stores.versions' => 'array',
            'cache-architecture.warming.connection' => 'sync',
            'cache-architecture.warming.user_portal_enabled' => true,
        ]);
    }

    public function test_owner_scopes_are_isolated_and_invalidation_dispatches_a_unique_warm_job(): void
    {
        Queue::fake();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $cache = app(UserPortalCache::class);

        $firstSnapshot = $cache->remember($first, 'summary', ['locale' => 'ru'], fn (): array => ['owner' => $first->public_id]);
        $secondSnapshot = $cache->remember($second, 'summary', ['locale' => 'ru'], fn (): array => ['owner' => $second->public_id]);

        $this->assertSame($first->public_id, $firstSnapshot['owner']);
        $this->assertSame($second->public_id, $secondSnapshot['owner']);

        app(UserPortalCacheInvalidator::class)->changed($first);

        $firstRebuilt = $cache->remember($first, 'summary', ['locale' => 'ru'], fn (): array => ['owner' => 'rebuilt']);
        $secondStillCached = $cache->remember($second, 'summary', ['locale' => 'ru'], fn (): array => ['owner' => 'wrong']);

        $this->assertSame('rebuilt', $firstRebuilt['owner']);
        $this->assertSame($second->public_id, $secondStillCached['owner']);

        Queue::assertPushed(WarmUserPortalCache::class, fn (WarmUserPortalCache $job): bool => $job->userPublicId === $first->public_id);
        Queue::assertNotPushed(WarmUserPortalCache::class, fn (WarmUserPortalCache $job): bool => $job->userPublicId === $second->public_id);
        $job = new WarmUserPortalCache((string) $first->public_id);
        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertTrue($job->afterCommit);
        $this->assertSame('user-portal:'.$first->public_id, $job->uniqueId());
    }

    public function test_command_warms_one_user_inline_and_queues_multiple_users(): void
    {
        Queue::fake();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->artisan('cache:warm-user-portal', ['users' => [(string) $first->public_id]])
            ->expectsOutputToContain('Прогрет пользователь')
            ->assertSuccessful();
        Queue::assertNothingPushed();

        $this->artisan('cache:warm-user-portal', [
            'users' => [(string) $first->public_id, (string) $second->public_id],
        ])->expectsOutputToContain('Поставлено в очередь пользователей: 2')
            ->assertSuccessful();
        Queue::assertPushed(WarmUserPortalCache::class, 2);
    }

    public function test_all_demo_queues_only_the_exact_configured_allowlist(): void
    {
        Queue::fake();
        config(['demo-data.user_count' => 2]);

        $allowed = User::factory()->createMany([
            ['email' => 'user1@example.com'],
            ['email' => 'user2@example.com'],
        ]);
        User::factory()->create(['email' => 'user3@example.com']);
        User::factory()->create(['email' => 'user999@example.com']);

        $this->artisan('cache:warm-user-portal', ['--all-demo' => true])
            ->expectsOutputToContain('Поставлено в очередь пользователей: 2')
            ->assertSuccessful();

        Queue::assertPushed(
            WarmUserPortalCache::class,
            2,
        );
        $this->assertSame(
            $allowed->pluck('public_id')->sort()->values()->all(),
            Queue::pushed(WarmUserPortalCache::class)
                ->map(fn (WarmUserPortalCache $job): string => $job->userPublicId)
                ->sort()
                ->values()
                ->all(),
        );
    }

    public function test_missing_ttl_config_fails_open_to_the_authoritative_rebuild(): void
    {
        $user = User::factory()->create();
        config(['cache-architecture.domains.user-portal' => null]);

        $snapshot = app(UserPortalCache::class)->remember(
            $user,
            'summary',
            [],
            fn (): array => ['source' => 'database'],
        );

        $this->assertSame(['source' => 'database'], $snapshot);
    }
}
