<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Actions\Administration\InvalidateAdministeredCache;
use App\Enums\AdminAuditAction;
use App\Enums\AdminMembershipStatus;
use App\Enums\AdminRoleCode;
use App\Models\AdminAuditEvent;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use App\Models\CatalogSearchIndexState;
use App\Models\User;
use App\Services\Admin\AdminOperationsQuery;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class AdminOperationsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function operations_page_reports_only_real_safe_capabilities_and_redacts_search_failures(): void
    {
        $operator = $this->administrator();
        CatalogSearchIndexState::query()->whereKey(CatalogSearchIndexState::SINGLETON_ID)->update([
            'status' => 'failed',
            'last_error' => 'secret=/private/path token=must-not-leak',
            'failed_at' => now(),
        ]);

        $this->actingAs($operator)
            ->get(route('admin.operations'))
            ->assertOk()
            ->assertSeeText(__('administration.operations.capabilities.database_search'))
            ->assertSeeText(__('administration.operations.states.installed'))
            ->assertSeeText(__('administration.operations.capabilities.payment_provider'))
            ->assertSeeText(__('administration.operations.states.unavailable'))
            ->assertDontSee('must-not-leak')
            ->assertDontSee('/private/path')
            ->assertDontSee('.env')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    #[Test]
    public function cache_invalidation_accepts_only_allowlisted_domains_and_is_audited(): void
    {
        Cache::setDefaultDriver('array');
        config(['cache-architecture.stores.versions' => 'array']);
        $operator = $this->administrator();
        $versions = app(CacheVersionRegistry::class);
        $before = $versions->version(CacheDomain::CatalogPages);

        $event = app(InvalidateAdministeredCache::class)->handle($operator, CacheDomain::CatalogPages->value, true);

        self::assertGreaterThan($before, $versions->version(CacheDomain::CatalogPages));
        self::assertSame('cache.invalidate', $event->action_code);
        self::assertSame(1, AdminAuditEvent::query()->where('action', AdminAuditAction::CacheInvalidated->value)->count());

        $this->expectException(\InvalidArgumentException::class);
        app(InvalidateAdministeredCache::class)->handle($operator, 'all', true);
    }

    #[Test]
    public function operations_summary_is_partially_available_when_schema_inspection_fails(): void
    {
        Schema::shouldReceive('hasTable')
            ->andThrow(new RuntimeException('secret database topology'));

        $summary = app(AdminOperationsQuery::class)->summary();

        self::assertSame([], $summary['capabilities']);
        self::assertNull($summary['search']);
        self::assertSame([], $summary['events']);
        self::assertTrue($summary['capabilities_error']);
        self::assertTrue($summary['search_error']);
        self::assertTrue($summary['events_error']);
        self::assertArrayNotHasKey('exception', $summary);
    }

    private function administrator(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        AdminUserRole::query()->create([
            'user_id' => $user->id,
            'admin_role_id' => AdminRole::query()->where('code', AdminRoleCode::SystemOperator)->valueOrFail('id'),
            'status' => AdminMembershipStatus::Active,
            'reason_code' => 'operations_test',
            'assigned_at' => now(),
        ]);

        return $user;
    }
}
