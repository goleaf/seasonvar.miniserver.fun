<?php

declare(strict_types=1);

namespace App\Actions\HelpCenter;

use App\Enums\HelpPublicationStatus;
use App\Enums\HelpReportReason;
use App\Models\HelpArticle;
use App\Models\HelpArticleReport;
use App\Models\HelpArticleTranslation;
use App\Services\HelpCenter\HelpActorKey;
use App\Support\UserPlainText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ReportOutdatedHelpArticle
{
    public function __construct(private HelpActorKey $actors) {}

    public function handle(
        Request $request,
        int $articleId,
        int $translationId,
        HelpReportReason $reason,
        ?string $details,
    ): HelpArticleReport {
        $actorKey = $this->actors->for($request);
        $rateKey = 'help-report:'.$actorKey;

        if (RateLimiter::tooManyAttempts($rateKey, max(1, (int) config('help-center.reports.attempts', 5)))) {
            throw ValidationException::withMessages(['report' => [__('help.errors.rate_limited')]]);
        }

        RateLimiter::hit($rateKey, max(60, (int) config('help-center.reports.decay_seconds', 3600)));
        $article = HelpArticle::query()->whereKey($articleId)->where('status', HelpPublicationStatus::Published->value)->firstOrFail();
        Gate::authorize('view', $article);
        $translation = HelpArticleTranslation::query()
            ->whereKey($translationId)
            ->where('help_article_id', $article->id)
            ->where('is_published', true)
            ->firstOrFail();
        $clean = UserPlainText::description($details);
        $clean = is_string($clean)
            ? Str::limit($clean, max(1, (int) config('help-center.reports.details_maximum', 1000)), '')
            : null;
        $dedupe = hash('sha256', implode('|', [$translation->id, $actorKey, $reason->value, $translation->updated_at?->getTimestamp()]));

        return HelpArticleReport::query()->firstOrCreate(['dedupe_key' => $dedupe], [
            'public_id' => (string) Str::uuid(),
            'help_article_id' => $article->id,
            'help_article_translation_id' => $translation->id,
            'reporter_id' => $request->user()?->id,
            'actor_key' => $actorKey,
            'locale' => $translation->locale,
            'reason' => $reason,
            'details' => $clean,
            'status' => 'open',
        ]);
    }
}
