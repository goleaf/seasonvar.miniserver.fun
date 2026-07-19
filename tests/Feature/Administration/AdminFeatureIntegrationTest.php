<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Enums\AdminMembershipStatus;
use App\Enums\AdminRoleCode;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use App\Models\User;
use App\Services\Admin\AdminNavigationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminFeatureIntegrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_real_administration_destination_uses_one_navigation_registry_and_registered_route(): void
    {
        $definitions = app(AdminNavigationRegistry::class)->definitions();

        self::assertSame(count($definitions), collect($definitions)->pluck('code')->unique()->count());

        foreach ($definitions as $definition) {
            self::assertTrue(Route::has($definition['route']), $definition['route']);
            self::assertStringStartsWith('admin.', $definition['route']);
        }

        $featureOwners = [
            'serials' => 'admin.catalog',
            'seasons' => 'admin.catalog',
            'episodes' => 'admin.catalog',
            'sources' => 'admin.catalog',
            'subtitles' => 'admin.catalog',
            'editorial_translations' => 'admin.catalog',
            'people_genres_countries' => 'admin.catalog',
            'collections' => 'admin.catalog',
            'recommendation_relations' => 'admin.catalog',
            'tags' => 'admin.tags',
            'comments' => 'admin.comments',
            'reviews' => 'admin.reviews',
            'profiles' => 'admin.profiles',
            'content_requests' => 'admin.requests',
            'technical_tickets' => 'admin.issues',
            'help_center' => 'admin.help',
            'release_calendar' => 'admin.calendar',
            'premium_and_billing' => 'admin.premium',
            'approved_importer' => 'admin.imports',
            'users' => 'admin.users',
            'roles_permissions' => 'admin.access',
            'audit' => 'admin.audit',
            'cache_search_seo_health' => 'admin.operations',
        ];

        foreach ($featureOwners as $feature => $routeName) {
            self::assertTrue(Route::has($routeName), "{$feature}: {$routeName}");
        }
    }

    #[Test]
    public function absent_domains_are_not_exposed_as_routes_and_admin_urls_are_not_in_sitemaps(): void
    {
        self::assertFalse(Route::has('admin.advertisers'));
        self::assertFalse(Route::has('admin.rights-holders'));
        self::assertFalse(Route::has('admin.logs'));
        self::assertFalse(Route::has('admin.impersonation'));

        $this->get(route('sitemap.index'))->assertOk()->assertDontSee('/admin', false);
        $this->get(route('sitemap.static'))->assertOk()->assertDontSee('/admin', false);
    }

    #[Test]
    public function profile_moderation_uses_its_section_permission_in_the_resource_policy(): void
    {
        $moderator = User::factory()->create(['email_verified_at' => now()]);
        AdminUserRole::query()->create([
            'user_id' => $moderator->id,
            'admin_role_id' => AdminRole::query()->where('code', AdminRoleCode::Moderator)->valueOrFail('id'),
            'status' => AdminMembershipStatus::Active,
            'reason_code' => 'profile_policy_test',
            'assigned_at' => now(),
        ]);

        $this->actingAs($moderator)
            ->get(route('admin.profiles'))
            ->assertOk()
            ->assertSeeText(__('profiles.admin.title'));
    }
}
