<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogRecommendationDiversityPreference;
use App\Enums\CatalogRecommendationFreshnessPreference;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\User;
use App\Services\Catalog\CatalogPersonalPreferenceProfileBuilder;
use App\Services\Catalog\CatalogRecommendationPreferenceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogRecommendationProfileResetTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_profile_reset_ignores_old_semantic_evidence_and_learns_from_new_activity(): void
    {
        CarbonImmutable::setTestNow('2026-07-26 12:00:00 UTC');
        $user = User::factory()->create();
        $old = CatalogTitle::factory()->create();
        $new = CatalogTitle::factory()->create();
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $old->id,
            'rating' => 10,
            'rating_updated_at' => CarbonImmutable::now()->subDay(),
        ]);
        app(CatalogRecommendationPreferenceService::class)->reset($user);
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $new->id,
            'rating' => 10,
            'rating_updated_at' => CarbonImmutable::now()->addSecond(),
        ]);

        $sourceIds = app(CatalogPersonalPreferenceProfileBuilder::class)
            ->forUser($user)
            ->sourceTitleIds();

        $this->assertNotContains($old->id, $sourceIds);
        $this->assertContains($new->id, $sourceIds);
    }

    public function test_updating_preferences_does_not_reset_existing_profile_evidence(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create();
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'rating' => 10,
            'rating_updated_at' => now(),
        ]);
        app(CatalogRecommendationPreferenceService::class)->update(
            $user,
            CatalogRecommendationDiversityPreference::Focused,
            CatalogRecommendationFreshnessPreference::Proven,
        );

        $this->assertContains(
            $title->id,
            app(CatalogPersonalPreferenceProfileBuilder::class)->forUser($user)->sourceTitleIds(),
        );
    }
}
