<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogRecommendationFeedback;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\User;
use App\Services\Auth\AccountDataExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogRecommendationFeedbackExternalContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_export_preserves_the_stable_positive_feedback_value(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create(['slug' => 'export-positive-feedback']);
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'recommendation_feedback' => CatalogRecommendationFeedback::MoreLikeThis,
            'recommendation_feedback_updated_at' => now(),
        ]);

        $library = app(AccountDataExportService::class)->export($user)['library'];

        $this->assertCount(1, $library);
        $this->assertSame('export-positive-feedback', $library[0]['title_slug']);
        $this->assertSame('more_like_this', $library[0]['recommendation_feedback']);
        $this->assertNotNull($library[0]['recommendation_feedback_updated_at']);
    }
}
