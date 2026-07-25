<?php

declare(strict_types=1);

namespace Tests\Feature\Premium;

use App\Enums\AdminMembershipStatus;
use App\Enums\AdminRoleCode;
use App\Enums\PremiumAuditAction;
use App\Enums\PremiumEntitlementSource;
use App\Enums\PremiumFeature;
use App\Livewire\Premium\PremiumAdministrationManager;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use App\Models\PremiumAuditEvent;
use App\Models\PremiumEntitlement;
use App\Models\PremiumPromotion;
use App\Models\User;
use App\Services\Premium\PremiumAdministrationQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class PremiumAdministrationQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-25 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_user_lookup_uses_prepared_identity_paths_and_preserves_legacy_email_case(): void
    {
        $indexed = User::factory()->create(['email' => 'indexed@example.com']);
        $legacy = User::factory()->create(['email' => 'Legacy.Case@Example.com']);
        $query = app(PremiumAdministrationQuery::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $byPublicId = $query->findUser((string) $indexed->public_id);
        $byEmail = $query->findUser(' INDEXED@EXAMPLE.COM ');
        $byLegacyEmail = $query->findUser('legacy.case@example.com');
        $sql = collect(DB::getQueryLog())->pluck('query');

        DB::disableQueryLog();

        self::assertSame(
            ['public_id' => $indexed->public_id, 'name' => $indexed->name, 'email' => $indexed->email],
            $byPublicId,
        );
        self::assertSame($byPublicId, $byEmail);
        self::assertSame(
            ['public_id' => $legacy->public_id, 'name' => $legacy->name, 'email' => $legacy->email],
            $byLegacyEmail,
        );
        self::assertCount(4, $sql);
        self::assertStringContainsString('"public_id" = ?', $sql->get(0));
        self::assertStringContainsString('"email" = ?', $sql->get(1));
        self::assertStringContainsString('"email" = ?', $sql->get(2));
        self::assertStringContainsString('lower(email) = ?', $sql->get(3));

        foreach ($sql as $statement) {
            self::assertStringNotContainsString('select *', mb_strtolower($statement));
            self::assertStringNotContainsString('"password"', mb_strtolower($statement));
        }
    }

    public function test_page_returns_bounded_safe_prepared_sections_and_stable_audit_paginator(): void
    {
        $selected = User::factory()->create();
        $actor = User::factory()->create();
        $expectedEntitlements = [];
        $expectedPromotions = [];
        $expectedAudits = [];

        foreach (range(1, 31) as $position) {
            $entitlement = $this->createEntitlement($selected, $position);
            $expectedEntitlements[] = $entitlement->public_id;
        }

        foreach (range(1, 21) as $position) {
            $promotion = $this->createPromotion($position);
            $expectedPromotions[] = $promotion->public_id;
        }

        foreach (range(1, 22) as $position) {
            $event = $this->createAuditEvent($selected, $actor, $position);
            $expectedAudits[] = $event->resource_type;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $page = app(PremiumAdministrationQuery::class)->page(
            (string) $selected->public_id,
            canManageGrants: true,
            canManagePromotions: true,
            canViewAudit: true,
        );
        $allSql = collect(DB::getQueryLog())->pluck('query');
        $sql = $allSql
            ->filter(static fn (string $statement): bool => str_contains($statement, 'premium_'))
            ->values();

        DB::disableQueryLog();

        self::assertTrue($page['schema_ready']);
        self::assertSame(['name' => $selected->name, 'email' => $selected->email], $page['selected_user']);
        self::assertSame(
            array_slice(array_reverse($expectedEntitlements), 0, 30),
            $page['entitlements']->pluck('public_id')->all(),
        );
        self::assertSame(
            ['public_id', 'feature', 'source', 'period', 'can_revoke'],
            array_keys($page['entitlements']->first()),
        );
        self::assertSame(
            array_slice(array_reverse($expectedPromotions), 0, 20),
            $page['promotions']->pluck('public_id')->all(),
        );
        self::assertSame(
            ['public_id', 'code', 'redemptions', 'limit', 'duration'],
            array_keys($page['promotions']->first()),
        );
        self::assertSame('premiumAuditPage', $page['audits']->getPageName());
        self::assertSame(20, $page['audits']->perPage());
        self::assertSame(22, $page['audits']->total());
        self::assertSame(
            array_slice(array_reverse($expectedAudits), 0, 20),
            collect($page['audits']->items())->pluck('resource_type')->all(),
        );
        self::assertSame(
            ['action', 'occurred_at', 'actor', 'resource_type'],
            array_keys($page['audits']->items()[0]),
        );
        self::assertCount(8, $allSql);
        self::assertCount(4, $sql);

        foreach ($sql as $statement) {
            $normalized = mb_strtolower($statement);

            self::assertStringNotContainsString('select *', $normalized);
            self::assertStringNotContainsString('"private_note"', $normalized);
            self::assertStringNotContainsString('"context"', $normalized);
            self::assertStringNotContainsString('"idempotency_key"', $normalized);
            self::assertStringNotContainsString('"resource_public_id"', $normalized);
        }
    }

    public function test_denied_capabilities_return_empty_sections_without_domain_reads(): void
    {
        $selected = User::factory()->create();
        $query = app(PremiumAdministrationQuery::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $page = $query->page(
            (string) $selected->public_id,
            canManageGrants: false,
            canManagePromotions: false,
            canViewAudit: false,
        );
        $sql = collect(DB::getQueryLog())->pluck('query');

        DB::disableQueryLog();

        self::assertTrue($page['schema_ready']);
        self::assertNull($page['selected_user']);
        self::assertTrue($page['entitlements']->isEmpty());
        self::assertTrue($page['promotions']->isEmpty());
        self::assertSame(0, $page['audits']->total());
        self::assertSame('premiumAuditPage', $page['audits']->getPageName());
        self::assertCount(2, $sql);
        self::assertFalse($sql->contains(
            static fn (string $statement): bool => str_contains($statement, 'from "users"')
                || str_contains($statement, 'premium_entitlements')
                || str_contains($statement, 'premium_promotions')
                || str_contains($statement, 'premium_audit_events'),
        ));
    }

    public function test_livewire_keeps_premium_gates_and_uses_the_prepared_user_result(): void
    {
        $administrator = User::factory()->create();
        $selected = User::factory()->create(['email' => 'selected@example.com']);
        $this->assignPremiumManager($administrator);
        $entitlement = $this->createEntitlement($selected, 1);

        Livewire::actingAs($administrator)
            ->test(PremiumAdministrationManager::class)
            ->set('userSearch', ' SELECTED@EXAMPLE.COM ')
            ->call('findUser')
            ->assertHasNoErrors()
            ->assertSet('selectedUserPublicId', $selected->public_id)
            ->assertSet('userSearch', $selected->email)
            ->assertSee($selected->name)
            ->assertSee($selected->email)
            ->assertSee($entitlement->feature_code->label());
    }

    private function createEntitlement(User $user, int $position): PremiumEntitlement
    {
        return PremiumEntitlement::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'feature_code' => PremiumFeature::PremiumAccess,
            'source' => PremiumEntitlementSource::ManualGrant,
            'source_reference' => 'admin-query-'.$position,
            'application_key' => hash('sha256', 'admin-query-'.$position),
            'reason_code' => 'administration_query_test',
            'private_note' => 'Не должно выбираться.',
            'starts_at' => CarbonImmutable::now()->addMinutes($position),
            'ends_at' => CarbonImmutable::now()->addDays($position),
            'is_lifetime' => false,
        ]);
    }

    private function createPromotion(int $position): PremiumPromotion
    {
        $promotion = PremiumPromotion::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'ADMIN_QUERY_'.$position,
            'duration_days' => $position,
            'total_limit' => $position,
            'per_user_limit' => 1,
            'is_active' => true,
        ]);
        $createdAt = CarbonImmutable::now()->addMinutes($position);
        $promotion->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $promotion;
    }

    private function createAuditEvent(User $user, User $actor, int $position): PremiumAuditEvent
    {
        return PremiumAuditEvent::query()->create([
            'public_id' => (string) Str::uuid(),
            'actor_id' => $actor->id,
            'user_id' => $user->id,
            'action' => PremiumAuditAction::EntitlementGranted,
            'resource_type' => 'entitlement_'.$position,
            'resource_public_id' => (string) Str::uuid(),
            'idempotency_key' => hash('sha256', 'admin-audit-'.$position),
            'context' => ['private' => 'Не должно выбираться.'],
            'occurred_at' => CarbonImmutable::now()->addMinutes($position),
        ]);
    }

    private function assignPremiumManager(User $user): void
    {
        AdminUserRole::query()->create([
            'user_id' => $user->id,
            'admin_role_id' => AdminRole::query()->where('code', AdminRoleCode::PremiumManager)->valueOrFail('id'),
            'status' => AdminMembershipStatus::Active,
            'reason_code' => 'premium_administration_query_test',
            'assigned_at' => CarbonImmutable::now(),
        ]);
    }
}
