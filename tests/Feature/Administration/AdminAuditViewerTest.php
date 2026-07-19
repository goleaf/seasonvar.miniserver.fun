<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Enums\AdminAuditAction;
use App\Enums\AdminMembershipStatus;
use App\Enums\AdminRoleCode;
use App\Models\AdminAuditEvent;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use App\Models\User;
use App\Services\Admin\AdminAuditQuery;
use App\Support\Administration\AdminTableState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminAuditViewerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function audit_viewer_is_bounded_translated_and_never_exposes_fingerprints_or_private_payloads(): void
    {
        $auditor = $this->administrator(AdminRoleCode::ReadOnlyAuditor);

        foreach (range(1, 30) as $index) {
            AdminAuditEvent::query()->create([
                'actor_id' => $auditor->id,
                'action' => AdminAuditAction::TitleUpdated,
                'resource_type' => 'catalog_title',
                'resource_id' => $index,
                'before_version' => hash('sha256', "before-private-{$index}"),
                'after_version' => hash('sha256', "after-private-{$index}"),
                'changed_fields' => ['title'],
                'occurred_at' => now()->subMinutes($index),
            ]);
        }

        $response = $this->actingAs($auditor)->get(route('admin.audit'));

        $response
            ->assertOk()
            ->assertSeeText(__('administration.audit.actions.title_updated'))
            ->assertSeeText(__('administration.audit.resources.catalog_title'))
            ->assertDontSee(hash('sha256', 'before-private-1'))
            ->assertDontSee(hash('sha256', 'after-private-1'))
            ->assertDontSee('before-private')
            ->assertSee('auditPage', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    #[Test]
    public function audit_viewer_requires_audit_permission(): void
    {
        $moderator = $this->administrator(AdminRoleCode::Moderator);

        $this->actingAs($moderator)->get('/admin/audit')->assertForbidden();
    }

    #[Test]
    public function audit_date_filters_are_strict_and_cannot_expand_beyond_the_ninety_day_window(): void
    {
        $auditor = $this->administrator(AdminRoleCode::ReadOnlyAuditor);

        foreach ([now()->subDays(100), now()->subDay()] as $occurredAt) {
            AdminAuditEvent::query()->create([
                'actor_id' => $auditor->id,
                'action' => AdminAuditAction::TitleUpdated,
                'resource_type' => 'catalog_title',
                'resource_id' => fake()->numberBetween(1, 100000),
                'before_version' => hash('sha256', fake()->uuid()),
                'after_version' => hash('sha256', fake()->uuid()),
                'changed_fields' => ['title'],
                'occurred_at' => $occurredAt,
            ]);
        }

        self::assertSame(1, app(AdminAuditQuery::class)->paginate($this->auditState([
            'from' => '1900-01-01',
            'to' => '2999-01-01',
        ]))->total());
        self::assertSame(2, app(AdminAuditQuery::class)->paginate($this->auditState([
            'from' => '2026-02-30',
            'to' => 'not-a-date',
        ]))->total());
    }

    private function administrator(AdminRoleCode $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        AdminUserRole::query()->create([
            'user_id' => $user->id,
            'admin_role_id' => AdminRole::query()->where('code', $role)->valueOrFail('id'),
            'status' => AdminMembershipStatus::Active,
            'reason_code' => 'audit_test',
            'assigned_at' => now(),
        ]);

        return $user;
    }

    /** @param array<string, string> $filters */
    private function auditState(array $filters): AdminTableState
    {
        return AdminTableState::from(
            ['filters' => $filters],
            ['occurred' => 'occurred_at'],
            'occurred',
            ['from', 'to'],
        );
    }
}
