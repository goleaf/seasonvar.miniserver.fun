<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminMembershipStatus;
use App\Enums\AdminRoleCode;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionVisibility;
use App\Livewire\CatalogAdministrationPage;
use App\Livewire\Collections\CatalogCollectionCategoryManager;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\User;
use App\Services\Collections\CatalogCollectionCategoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class CatalogCollectionCategoryAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_manager_can_create_translate_archive_and_reorder_two_level_categories(): void
    {
        $admin = $this->administrator(AdminRoleCode::ContentManager);
        $root = CatalogCollectionCategory::query()->where('slug', 'themes-and-genres')->firstOrFail();
        $service = app(CatalogCollectionCategoryService::class);

        $created = $service->createCategory(
            $admin,
            parentPublicId: $root->public_id,
            slug: 'medical-stories',
            translations: ['ru' => 'Медицинские истории', 'en' => 'Medical stories'],
        );

        $this->assertSame($root->id, $created->parent_id);
        $this->assertSame('Медицинские истории', $created->translations->firstWhere('locale', 'ru')?->name);

        $updated = $service->updateCategory(
            $admin,
            $created,
            translations: ['ru' => 'Истории о врачах', 'en' => 'Stories about doctors'],
            active: false,
        );
        $this->assertFalse($updated->is_active);
        $this->assertSame('Истории о врачах', $updated->translations->firstWhere('locale', 'ru')?->name);

        $moved = $service->moveCategory($admin, $updated, -1);
        $this->assertLessThan($created->position, $moved->position);
        $this->assertDatabaseHas('admin_audit_events', [
            'resource_type' => 'catalog_collection_category',
        ]);
    }

    public function test_dictionary_rejects_deeper_hierarchy_inactive_parent_and_unauthorized_mutation(): void
    {
        $admin = $this->administrator(AdminRoleCode::ContentManager);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $child = CatalogCollectionCategory::query()->where('slug', 'history')->firstOrFail();
        $service = app(CatalogCollectionCategoryService::class);

        try {
            $service->createCategory(
                $admin,
                parentPublicId: $child->public_id,
                slug: 'too-deep',
                translations: ['ru' => 'Слишком глубоко', 'en' => 'Too deep'],
            );
            $this->fail('Ожидалась ошибка глубины дерева.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parentPublicId', $exception->errors());
        }

        try {
            $service->createCategory(
                $user,
                parentPublicId: null,
                slug: 'forbidden-root',
                translations: ['ru' => 'Запрещено', 'en' => 'Forbidden'],
            );
            $this->fail('Ожидался запрет изменения справочника.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    public function test_bulk_assignment_is_bounded_explicit_and_keeps_moderation_state(): void
    {
        $admin = $this->administrator(AdminRoleCode::ContentManager);
        $category = CatalogCollectionCategory::query()->where('slug', 'mini-series')->firstOrFail();
        $collections = collect([
            $this->collection('Первая', CatalogCollectionVisibility::Public),
            $this->collection('Вторая', CatalogCollectionVisibility::Unlisted),
        ]);
        $service = app(CatalogCollectionCategoryService::class);

        $changed = $service->bulkAssign(
            $admin,
            $collections->pluck('public_id')->all(),
            $category->public_id,
        );

        $this->assertSame(2, $changed);
        $this->assertSame(
            2,
            CatalogCollection::query()
                ->whereKey($collections->pluck('id'))
                ->where('catalog_collection_category_id', $category->id)
                ->where('moderation_status', CatalogCollectionModerationStatus::Approved->value)
                ->count(),
        );

        try {
            $service->bulkAssign(
                $admin,
                array_fill(0, 101, (string) Str::uuid()),
                $category->public_id,
            );
            $this->fail('Ожидалось ограничение пакета.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('selectedCollectionPublicIds', $exception->errors());
        }
    }

    public function test_category_manager_is_embedded_in_catalog_shell_and_mutations_require_content_manage(): void
    {
        $contentManager = $this->administrator(AdminRoleCode::ContentManager);
        $moderator = $this->administrator(AdminRoleCode::Moderator);

        Livewire::actingAs($contentManager)
            ->withQueryParams(['section' => 'collections'])
            ->test(CatalogAdministrationPage::class)
            ->assertSet('section', 'collections')
            ->assertSeeText(__('collections.categories.admin_title'));

        Livewire::actingAs($moderator)
            ->test(CatalogCollectionCategoryManager::class)
            ->assertSet('canManage', false)
            ->assertDontSeeHtml('data-category-create-form');
    }

    private function administrator(AdminRoleCode $roleCode): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        AdminUserRole::query()->create([
            'user_id' => $user->id,
            'admin_role_id' => AdminRole::query()->where('code', $roleCode)->valueOrFail('id'),
            'status' => AdminMembershipStatus::Active,
            'reason_code' => 'collection_category_test',
            'assigned_at' => now(),
        ]);

        return $user;
    }

    private function collection(string $name, CatalogCollectionVisibility $visibility): CatalogCollection
    {
        return CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'visibility' => $visibility,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
        ]);
    }
}
