<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Enums\AdminMembershipStatus;
use App\Enums\AdminPermission;
use App\Enums\AdminRoleCode;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use App\Models\CatalogCollection;
use App\Models\CatalogTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminContentPermissionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function destructive_catalog_actions_require_their_action_level_permission(): void
    {
        $editor = $this->administrator(AdminRoleCode::ContentEditor);
        $title = CatalogTitle::factory()->create();

        self::assertTrue(Gate::forUser($editor)->allows('viewAdmin', $title));
        self::assertTrue(Gate::forUser($editor)->allows('update', $title));
        self::assertFalse(Gate::forUser($editor)->allows('archive', $title));
        self::assertFalse(Gate::forUser($editor)->allows(AdminPermission::ContentDelete->value));
    }

    #[Test]
    public function media_manager_can_enter_the_shared_catalog_without_receiving_metadata_edit_permission(): void
    {
        $mediaManager = $this->administrator(AdminRoleCode::MediaManager);
        $title = CatalogTitle::factory()->create();

        self::assertTrue(Gate::forUser($mediaManager)->allows('viewAdmin', $title));
        self::assertFalse(Gate::forUser($mediaManager)->allows('update', $title));
        self::assertTrue(Gate::forUser($mediaManager)->allows(AdminPermission::SourcesManage->value));

        $this->actingAs($mediaManager)
            ->get(route('admin.catalog'))
            ->assertOk()
            ->assertSeeText(__('collections.admin.catalog_and_collections'));
    }

    #[Test]
    public function moderator_can_reach_the_collection_queue_without_receiving_catalog_edit_permission(): void
    {
        $moderator = $this->administrator(AdminRoleCode::Moderator);

        self::assertTrue(Gate::forUser($moderator)->allows(AdminPermission::ContentView->value));
        self::assertTrue(Gate::forUser($moderator)->allows(AdminPermission::CollectionsModerate->value));
        self::assertTrue(Gate::forUser($moderator)->allows('moderate', CatalogCollection::class));
        self::assertFalse(Gate::forUser($moderator)->allows(AdminPermission::ContentManage->value));

        $this->actingAs($moderator)
            ->get(route('admin.catalog', ['section' => 'collections']))
            ->assertOk()
            ->assertSeeText(__('collections.admin.collections_section'));
    }

    private function administrator(AdminRoleCode $roleCode): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        AdminUserRole::query()->create([
            'user_id' => $user->id,
            'admin_role_id' => AdminRole::query()->where('code', $roleCode)->valueOrFail('id'),
            'status' => AdminMembershipStatus::Active,
            'reason_code' => 'content_permission_test',
            'assigned_at' => now(),
        ]);

        return $user;
    }
}
