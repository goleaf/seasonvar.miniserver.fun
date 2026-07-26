<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Enums\AdminMembershipStatus;
use App\Enums\AdminRoleCode;
use App\Enums\CatalogQualityIssueCategory;
use App\Enums\CatalogQualitySeverity;
use App\Enums\TagModerationStatus;
use App\Enums\TagProviderMappingStatus;
use App\Enums\TagSource;
use App\Enums\TagType;
use App\Enums\TagVisibility;
use App\Livewire\Administration\CatalogQualityCenterPage;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleQualityIssue;
use App\Models\CatalogTitleQualitySnapshot;
use App\Models\CatalogTitleTagSource;
use App\Models\Tag;
use App\Models\TagProviderMapping;
use App\Models\User;
use App\Services\Catalog\Quality\CatalogMetadataProvenanceRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CatalogQualityCenterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function route_requires_the_canonical_admin_boundary_and_content_view_permission(): void
    {
        $ordinary = User::factory()->create(['email_verified_at' => now()]);
        $viewer = $this->administrator(AdminRoleCode::Moderator);
        $supportAgent = $this->administrator(AdminRoleCode::SupportAgent);

        $this->get(route('admin.quality'))->assertRedirectToRoute('login');
        $this->actingAs($ordinary)->get(route('admin.quality'))->assertForbidden();
        $this->actingAs($supportAgent)->get(route('admin.quality'))->assertForbidden();
        $this->actingAs($viewer)
            ->get(route('admin.quality'))
            ->assertOk()
            ->assertSeeText('Центр качества каталога')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    #[Test]
    public function queue_score_search_sort_and_page_size_filters_work_together(): void
    {
        $viewer = $this->administrator(AdminRoleCode::Moderator);
        $matching = CatalogTitle::factory()->create(['title' => 'Цветок зла']);
        $other = CatalogTitle::factory()->create(['title' => 'Другой сериал']);

        CatalogTitleQualitySnapshot::factory()->for($matching)->create([
            'quality_score' => 40,
            'severity' => CatalogQualitySeverity::Warning,
        ]);
        CatalogTitleQualityIssue::factory()->for($matching)->create([
            'code' => 'suspicious_tags',
            'category' => CatalogQualityIssueCategory::SuspiciousTags,
            'severity' => CatalogQualitySeverity::Warning,
            'evidence' => ['count' => 2, 'examples' => ['Гномы', 'друиды']],
        ]);
        CatalogTitleQualitySnapshot::factory()->for($other)->create([
            'quality_score' => 10,
            'severity' => CatalogQualitySeverity::Critical,
        ]);
        CatalogTitleQualityIssue::factory()->for($other)->create([
            'code' => 'missing_video',
            'category' => CatalogQualityIssueCategory::MissingVideo,
            'severity' => CatalogQualitySeverity::Critical,
        ]);

        Livewire::actingAs($viewer)
            ->test(CatalogQualityCenterPage::class)
            ->set('queue', 'suspicious_tags')
            ->set('search', '  Цветок  ')
            ->set('minimumScore', 0)
            ->set('maximumScore', 40)
            ->set('sort', 'score_desc')
            ->set('perPage', 15)
            ->assertHasNoErrors()
            ->assertSee('Цветок зла')
            ->assertDontSee('Другой сериал')
            ->assertSet('search', 'Цветок');
    }

    #[Test]
    public function validation_preserves_zero_boundaries_and_rejects_tampered_or_impossible_filters(): void
    {
        $viewer = $this->administrator(AdminRoleCode::Moderator);

        Livewire::actingAs($viewer)
            ->test(CatalogQualityCenterPage::class)
            ->set('minimumScore', 0)
            ->set('maximumScore', 100)
            ->assertHasNoErrors()
            ->assertSet('minimumScore', 0)
            ->set('queue', 'unknown')
            ->assertHasErrors('queue');

        Livewire::actingAs($viewer)
            ->test(CatalogQualityCenterPage::class)
            ->set('minimumScore', 80)
            ->set('maximumScore', 20)
            ->assertHasErrors('maximum_score');

        Livewire::actingAs($viewer)
            ->test(CatalogQualityCenterPage::class)
            ->set('sort', 'raw_sql')
            ->assertHasErrors('sort');

        Livewire::actingAs($viewer)
            ->test(CatalogQualityCenterPage::class)
            ->set('perPage', 5000)
            ->assertHasErrors('per_page');
    }

    #[Test]
    public function reset_returns_all_filter_state_and_pagination_to_defaults(): void
    {
        $viewer = $this->administrator(AdminRoleCode::Moderator);

        Livewire::actingAs($viewer)
            ->test(CatalogQualityCenterPage::class)
            ->set('queue', 'critical')
            ->set('search', 'Сериал')
            ->set('minimumScore', 10)
            ->set('maximumScore', 50)
            ->set('sort', 'title')
            ->set('perPage', 15)
            ->call('resetFilters')
            ->assertSet('queue', 'all')
            ->assertSet('search', '')
            ->assertSet('minimumScore', null)
            ->assertSet('maximumScore', null)
            ->assertSet('sort', 'score_asc')
            ->assertSet('perPage', 25)
            ->assertHasNoErrors();
    }

    #[Test]
    public function cards_explain_field_and_tag_provenance_without_exposing_private_urls(): void
    {
        $viewer = $this->administrator(AdminRoleCode::Moderator);
        $title = CatalogTitle::factory()->create([
            'title' => 'Цветок зла',
            'year' => 2020,
            'source_url' => 'https://seasonvar.ru/private-title-page',
        ]);
        CatalogTitleQualitySnapshot::factory()->for($title)->create([
            'quality_score' => 72,
        ]);
        app(CatalogMetadataProvenanceRecorder::class)->recordProviderSnapshot(
            $title,
            $title->sourcePage,
            ['year' => 2020],
        );
        $tag = Tag::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => 'Гномы',
            'slug' => 'gnomy-quality-test',
            'type' => TagType::Imported,
            'visibility' => TagVisibility::Public,
            'moderation_status' => TagModerationStatus::Approved,
            'source' => TagSource::Seasonvar,
            'normalized_name' => 'гномы',
            'normalized_name_hash' => hash('sha256', 'гномы'),
        ]);
        $title->tags()->attach($tag);
        $providerKey = hash('sha256', 'provider-tag:gnomes');
        TagProviderMapping::query()->create([
            'provider' => 'seasonvar',
            'provider_key' => $providerKey,
            'tag_id' => $tag->id,
            'raw_label' => 'Гномы',
            'normalized_name' => 'гномы',
            'normalized_name_hash' => hash('sha256', 'гномы'),
            'status' => TagProviderMappingStatus::Pending,
            'confidence' => 12,
            'last_seen_at' => now(),
        ]);
        CatalogTitleTagSource::query()->create([
            'catalog_title_id' => $title->id,
            'tag_id' => $tag->id,
            'source' => TagSource::Seasonvar,
            'provider' => 'seasonvar',
            'source_id' => $title->source_id,
            'source_key' => $providerKey,
            'is_current' => true,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        Livewire::actingAs($viewer)
            ->test(CatalogQualityCenterPage::class)
            ->assertSeeText('Происхождение данных')
            ->assertSeeText('Год')
            ->assertSeeText('Seasonvar')
            ->assertSeeText('98%')
            ->assertSeeText('Гномы')
            ->assertSeeText('12%')
            ->assertSeeText('Проверить')
            ->assertDontSee('https://seasonvar.ru/private-title-page');
    }

    #[Test]
    public function url_state_applies_combined_filters_and_tampered_query_values_do_not_execute(): void
    {
        $viewer = $this->administrator(AdminRoleCode::Moderator);
        $matching = CatalogTitle::factory()->create(['title' => 'Цветок зла']);
        $other = CatalogTitle::factory()->create(['title' => 'Другой сериал']);
        CatalogTitleQualitySnapshot::factory()->for($matching)->create(['quality_score' => 40]);
        CatalogTitleQualityIssue::factory()->for($matching)->create([
            'code' => 'suspicious_tags',
            'category' => CatalogQualityIssueCategory::SuspiciousTags,
        ]);
        CatalogTitleQualitySnapshot::factory()->for($other)->create(['quality_score' => 20]);

        $this->actingAs($viewer)
            ->get(route('admin.quality', [
                'quality_queue' => 'suspicious_tags',
                'quality_q' => 'Цветок',
                'quality_min' => 0,
                'quality_max' => 40,
                'quality_sort' => 'score_desc',
                'quality_per_page' => 15,
            ]))
            ->assertOk()
            ->assertSeeText('Цветок зла')
            ->assertDontSeeText('Другой сериал');

        $this->actingAs($viewer)
            ->get(route('admin.quality', [
                'quality_queue' => 'unknown',
                'quality_min' => -1,
                'quality_max' => 101,
                'quality_sort' => 'raw_sql',
                'quality_per_page' => 5000,
            ]))
            ->assertOk()
            ->assertSeeText('Выбрана недопустимая очередь качества.');
    }

    private function administrator(AdminRoleCode $roleCode): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        AdminUserRole::query()->create([
            'user_id' => $user->id,
            'admin_role_id' => AdminRole::query()->where('code', $roleCode)->valueOrFail('id'),
            'status' => AdminMembershipStatus::Active,
            'reason_code' => 'catalog_quality_test',
            'assigned_at' => now(),
        ]);

        return $user;
    }
}
