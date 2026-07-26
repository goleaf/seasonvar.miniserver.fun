<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\CatalogRecommendationFeedback;
use App\Enums\CatalogRecommendationFeedbackReason;
use App\Models\CatalogRecommendationFeedbackDetail;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CatalogRecommendationFeedbackService
{
    public function __construct(
        private readonly CatalogUserStateService $userStates,
        private readonly CatalogRecommendationPreferenceSchema $schema,
    ) {}

    public function save(
        User $user,
        CatalogTitle $catalogTitle,
        CatalogRecommendationFeedbackReason $reason,
        ?int $subjectId = null,
    ): CatalogTitleUserState {
        $this->assertAvailable();
        $subject = $this->validatedSubject($catalogTitle, $reason, $subjectId);

        return DB::transaction(function () use ($catalogTitle, $reason, $subject, $user): CatalogTitleUserState {
            $state = $this->userStates->setRecommendationFeedback($user, $catalogTitle, $reason->feedback());

            CatalogRecommendationFeedbackDetail::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'catalog_title_id' => $catalogTitle->id,
                ],
                [
                    'reason' => $reason,
                    'genre_id' => $reason === CatalogRecommendationFeedbackReason::DislikeGenre ? $subject : null,
                    'country_id' => $reason === CatalogRecommendationFeedbackReason::DislikeCountry ? $subject : null,
                    'actor_id' => $reason === CatalogRecommendationFeedbackReason::DislikeActor ? $subject : null,
                ],
            );

            return $state;
        }, attempts: 3);
    }

    public function savePositive(User $user, CatalogTitle $catalogTitle): CatalogTitleUserState
    {
        $this->assertAvailable();

        return DB::transaction(function () use ($catalogTitle, $user): CatalogTitleUserState {
            $state = $this->userStates->setRecommendationFeedback(
                $user,
                $catalogTitle,
                CatalogRecommendationFeedback::MoreLikeThis,
            );
            $this->detailQuery($user, $catalogTitle)->delete();

            return $state;
        }, attempts: 3);
    }

    public function undo(User $user, CatalogTitle $catalogTitle): ?CatalogTitleUserState
    {
        $this->assertAvailable();

        return DB::transaction(function () use ($catalogTitle, $user): ?CatalogTitleUserState {
            $state = $this->userStates->undoRecommendationFeedback($user, $catalogTitle);
            $this->detailQuery($user, $catalogTitle)->delete();

            return $state;
        }, attempts: 3);
    }

    private function validatedSubject(
        CatalogTitle $catalogTitle,
        CatalogRecommendationFeedbackReason $reason,
        ?int $subjectId,
    ): ?int {
        if (! $reason->requiresSubject()) {
            if ($subjectId !== null) {
                $this->subjectValidationFailure();
            }

            return null;
        }

        if ($subjectId === null || $subjectId < 1) {
            $this->subjectValidationFailure();
        }

        $relationship = match ($reason) {
            CatalogRecommendationFeedbackReason::DislikeGenre => 'genres',
            CatalogRecommendationFeedbackReason::DislikeCountry => 'countries',
            CatalogRecommendationFeedbackReason::DislikeActor => 'actors',
            default => throw new \LogicException('Unexpected recommendation feedback subject.'),
        };

        if (! $catalogTitle->{$relationship}()->whereKey($subjectId)->exists()) {
            $this->subjectValidationFailure();
        }

        return $subjectId;
    }

    private function subjectValidationFailure(): never
    {
        throw ValidationException::withMessages([
            'recommendationFeedbackSubject' => __('recommendations.feedback.invalid_subject'),
        ]);
    }

    private function assertAvailable(): void
    {
        if (! $this->schema->ready()) {
            throw ValidationException::withMessages([
                'recommendationFeedback' => __('recommendations.feedback.unavailable'),
            ]);
        }
    }

    /** @return Builder<CatalogRecommendationFeedbackDetail> */
    private function detailQuery(User $user, CatalogTitle $catalogTitle): Builder
    {
        return CatalogRecommendationFeedbackDetail::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($catalogTitle);
    }
}
