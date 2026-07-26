<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogRecommendationFeedback;
use App\Enums\CatalogRecommendationFeedbackReason;
use App\Models\Actor;
use App\Models\CatalogRecommendationFeedbackDetail;
use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Genre;
use App\Models\User;
use App\Services\Catalog\CatalogRecommendationFeedbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CatalogRecommendationFeedbackServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_reason_is_saved_as_one_current_private_detail_with_canonical_feedback(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create();
        $service = app(CatalogRecommendationFeedbackService::class);

        foreach (CatalogRecommendationFeedbackReason::cases() as $reason) {
            $subjectId = $this->attachRequiredSubject($title, $reason);
            $state = $service->save($user, $title, $reason, $subjectId);
            $detail = CatalogRecommendationFeedbackDetail::query()
                ->whereBelongsTo($user)
                ->whereBelongsTo($title)
                ->sole();

            $this->assertSame($reason->feedback(), $state->recommendation_feedback);
            $this->assertSame($reason, $detail->reason);
            $this->assertSame(
                $reason === CatalogRecommendationFeedbackReason::DislikeGenre ? $subjectId : null,
                $detail->genre_id,
            );
            $this->assertSame(
                $reason === CatalogRecommendationFeedbackReason::DislikeCountry ? $subjectId : null,
                $detail->country_id,
            );
            $this->assertSame(
                $reason === CatalogRecommendationFeedbackReason::DislikeActor ? $subjectId : null,
                $detail->actor_id,
            );
            $this->assertSame(1, CatalogRecommendationFeedbackDetail::query()->whereBelongsTo($user)->count());
        }
    }

    public function test_taxonomy_subject_is_required_and_must_belong_to_the_exact_title(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create();
        $foreignGenre = Genre::query()->create([
            'name' => 'Чужой жанр',
            'slug' => 'foreign-feedback-genre',
        ]);
        $service = app(CatalogRecommendationFeedbackService::class);

        foreach ([null, $foreignGenre->id] as $subjectId) {
            try {
                $service->save(
                    $user,
                    $title,
                    CatalogRecommendationFeedbackReason::DislikeGenre,
                    $subjectId,
                );
                $this->fail('Ожидалась server-side validation ошибки subject.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('recommendationFeedbackSubject', $exception->errors());
            }
        }

        $this->assertDatabaseMissing('catalog_title_user_states', [
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
        ]);
        $this->assertSame(0, CatalogRecommendationFeedbackDetail::query()->count());
    }

    public function test_non_taxonomy_reason_rejects_a_subject_and_undo_clears_both_records(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create();
        $service = app(CatalogRecommendationFeedbackService::class);

        try {
            $service->save(
                $user,
                $title,
                CatalogRecommendationFeedbackReason::TooOld,
                123,
            );
            $this->fail('Ожидался запрет лишнего subject ID.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('recommendationFeedbackSubject', $exception->errors());
        }

        $state = $service->save($user, $title, CatalogRecommendationFeedbackReason::NotThisTitle);
        $this->assertSame(CatalogRecommendationFeedback::Blacklisted, $state->recommendation_feedback);
        $this->assertNull($service->undo($user, $title)?->recommendation_feedback);
        $this->assertDatabaseMissing('catalog_recommendation_feedback_details', [
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
        ]);
    }

    public function test_positive_feedback_clears_a_stale_negative_detail(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create();
        $service = app(CatalogRecommendationFeedbackService::class);
        $service->save($user, $title, CatalogRecommendationFeedbackReason::NotSimilar);

        $state = $service->savePositive($user, $title);

        $this->assertSame(CatalogRecommendationFeedback::MoreLikeThis, $state->recommendation_feedback);
        $this->assertDatabaseMissing('catalog_recommendation_feedback_details', [
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
        ]);
    }

    private function attachRequiredSubject(
        CatalogTitle $title,
        CatalogRecommendationFeedbackReason $reason,
    ): ?int {
        return match ($reason) {
            CatalogRecommendationFeedbackReason::DislikeGenre => tap(
                Genre::query()->create(['name' => 'Жанр '.str()->uuid(), 'slug' => (string) str()->uuid()]),
                fn (Genre $genre): mixed => $title->genres()->attach($genre),
            )->id,
            CatalogRecommendationFeedbackReason::DislikeCountry => tap(
                Country::query()->create(['name' => 'Страна '.str()->uuid(), 'slug' => (string) str()->uuid()]),
                fn (Country $country): mixed => $title->countries()->attach($country),
            )->id,
            CatalogRecommendationFeedbackReason::DislikeActor => tap(
                Actor::query()->create(['name' => 'Актёр '.str()->uuid(), 'slug' => (string) str()->uuid()]),
                fn (Actor $actor): mixed => $title->actors()->attach($actor),
            )->id,
            default => null,
        };
    }
}
