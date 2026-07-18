<?php

declare(strict_types=1);

namespace App\Actions\HelpCenter;

use App\Enums\HelpFeedbackReason;
use App\Enums\HelpFeedbackValue;
use App\Enums\HelpPublicationStatus;
use App\Models\HelpArticle;
use App\Models\HelpArticleFeedback;
use App\Models\HelpArticleTranslation;
use App\Services\HelpCenter\HelpActorKey;
use App\Services\HelpCenter\HelpCacheInvalidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final readonly class SubmitHelpFeedback
{
    public function __construct(private HelpActorKey $actors, private HelpCacheInvalidator $cache) {}

    public function handle(
        Request $request,
        int $articleId,
        int $translationId,
        HelpFeedbackValue $value,
        ?HelpFeedbackReason $reason,
    ): HelpArticleFeedback {
        $actorKey = $this->actors->for($request);
        $rateKey = 'help-feedback:'.$actorKey;

        if (RateLimiter::tooManyAttempts($rateKey, max(1, (int) config('help-center.feedback.attempts', 12)))) {
            throw ValidationException::withMessages(['feedback' => [__('help.errors.rate_limited')]]);
        }

        RateLimiter::hit($rateKey, max(60, (int) config('help-center.feedback.decay_seconds', 3600)));
        $article = HelpArticle::query()->whereKey($articleId)->where('status', HelpPublicationStatus::Published->value)->firstOrFail();
        Gate::authorize('view', $article);

        if (! $article->type->feedbackEnabled()) {
            throw ValidationException::withMessages(['feedback' => [__('help.errors.invalid_action')]]);
        }

        $translation = HelpArticleTranslation::query()
            ->whereKey($translationId)
            ->where('help_article_id', $article->id)
            ->where('is_published', true)
            ->firstOrFail();

        $changed = false;
        $effectiveReason = $value === HelpFeedbackValue::NotHelpful ? $reason : null;
        $feedback = DB::transaction(function () use (
            $translation,
            $actorKey,
            $article,
            $request,
            $value,
            $effectiveReason,
            &$changed,
        ): HelpArticleFeedback {
            $current = HelpArticleFeedback::query()
                ->where('help_article_translation_id', $translation->id)
                ->where('actor_key', $actorKey)
                ->lockForUpdate()
                ->first();

            if (! $current instanceof HelpArticleFeedback) {
                $changed = true;

                return HelpArticleFeedback::query()->create([
                    'help_article_id' => $article->id,
                    'help_article_translation_id' => $translation->id,
                    'user_id' => $request->user()?->id,
                    'actor_key' => $actorKey,
                    'locale' => $translation->locale,
                    'value' => $value,
                    'reason' => $effectiveReason,
                ]);
            }

            if ($current->value === $value && $current->reason === $effectiveReason) {
                return $current;
            }

            $changed = true;
            $current->forceFill([
                'user_id' => $request->user()?->id,
                'value' => $value,
                'reason' => $effectiveReason,
            ])->save();

            return $current;
        }, attempts: 3);

        if ($changed) {
            $this->cache->changed($article->public_id, sitemap: false);
        }

        return $feedback;
    }
}
