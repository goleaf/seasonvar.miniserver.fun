<?php

declare(strict_types=1);

namespace App\Livewire\HelpCenter;

use App\Actions\HelpCenter\ReportOutdatedHelpArticle;
use App\Actions\HelpCenter\SubmitHelpFeedback;
use App\Enums\HelpFeedbackReason;
use App\Enums\HelpFeedbackValue;
use App\Enums\HelpReportReason;
use App\Models\HelpArticleFeedback;
use App\Models\User;
use App\Services\HelpCenter\HelpActorKey;
use App\Services\HelpCenter\HelpCenterQuery;
use App\Services\HelpCenter\HelpCenterSchema;
use App\Services\HelpCenter\HelpLocale;
use App\Services\HelpCenter\HelpSeoPresenter;
use App\Services\HelpCenter\HelpUrlGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

final class HelpArticlePage extends Component
{
    #[Locked]
    public string $articleSlug = '';

    #[Locked]
    public int $articleId = 0;

    #[Locked]
    public int $translationId = 0;

    public string $feedbackValue = '';

    public string $feedbackReason = '';

    public bool $showReportForm = false;

    public string $reportReason = '';

    public string $reportDetails = '';

    public ?string $statusMessage = null;

    public ?string $actionError = null;

    public function mount(string $articleSlug, HelpCenterQuery $query, HelpLocale $locales, HelpUrlGenerator $urls): void
    {
        $this->articleSlug = mb_substr($articleSlug, 0, 180);
        $routeLocale = request()->route('locale');
        $routeLocale = is_string($routeLocale) ? $routeLocale : null;
        $locale = $locales->normalize($routeLocale ?? app()->getLocale());
        $user = auth()->user();
        $resolved = $query->resolveArticle($this->articleSlug, $locale, $user instanceof User ? $user : null);
        if ($resolved === null) {
            $replacement = $query->replacementUrl($this->articleSlug, $locale, $routeLocale);

            if ($replacement !== null) {
                throw new HttpResponseException(new RedirectResponse($replacement, 301));
            }
        }
        abort_if($resolved === null, 404, __('help.article.unavailable'));

        if ($resolved->legacySlug) {
            throw new HttpResponseException(new RedirectResponse($urls->article($resolved->translation, $routeLocale), 301));
        }

        $this->articleId = $resolved->article->id;
        $this->translationId = $resolved->translation->id;
    }

    public function submitFeedback(string $value, SubmitHelpFeedback $action): void
    {
        $this->actionError = null;
        $feedback = HelpFeedbackValue::tryFrom($value);

        if ($feedback === null) {
            $this->actionError = __('help.errors.invalid_action');

            return;
        }

        try {
            $reason = HelpFeedbackReason::tryFrom($this->feedbackReason);
            $action->handle(request(), $this->articleId, $this->translationId, $feedback, $reason);
            $this->feedbackValue = $feedback->value;
            $this->statusMessage = __('help.feedback.saved');
        } catch (Throwable $exception) {
            report($exception);
            $this->actionError = $exception instanceof ValidationException
                ? collect($exception->errors())->flatten()->first()
                : __('help.errors.feedback_failed');
        }
    }

    public function submitReport(ReportOutdatedHelpArticle $action): void
    {
        $validated = $this->validate([
            'reportReason' => ['required', Rule::enum(HelpReportReason::class)],
            'reportDetails' => ['nullable', 'string', 'max:'.max(1, (int) config('help-center.reports.details_maximum', 1000))],
        ]);
        $reason = HelpReportReason::from($validated['reportReason']);
        $this->actionError = null;

        try {
            $report = $action->handle(request(), $this->articleId, $this->translationId, $reason, $validated['reportDetails']);
            $this->showReportForm = false;
            $this->reset('reportReason', 'reportDetails');
            $this->statusMessage = $report->wasRecentlyCreated
                ? __('help.reports.saved')
                : __('help.reports.duplicate');
        } catch (Throwable $exception) {
            report($exception);
            $this->actionError = $exception instanceof ValidationException
                ? collect($exception->errors())->flatten()->first()
                : __('help.errors.report_failed');
        }
    }

    public function render(
        HelpCenterSchema $schema,
        HelpCenterQuery $query,
        HelpLocale $locales,
        HelpActorKey $actors,
        HelpSeoPresenter $seo,
    ): View {
        abort_unless($schema->ready(), 404);
        $routeLocale = request()->route('locale');
        $routeLocale = is_string($routeLocale) ? $routeLocale : null;
        $locale = $locales->normalize($routeLocale ?? app()->getLocale());
        $user = auth()->user();
        $resolved = $query->resolveArticle($this->articleSlug, $locale, $user instanceof User ? $user : null);
        abort_if($resolved === null, 404, __('help.article.unavailable'));
        $article = $query->article($resolved, $routeLocale, $user instanceof User ? $user : null);
        $this->articleId = $article->id;
        $this->translationId = $article->translationId;
        $current = $article->feedbackEnabled
            ? HelpArticleFeedback::query()
                ->where('help_article_translation_id', $article->translationId)
                ->where('actor_key', $actors->for(request()))
                ->first()
            : null;

        if ($current instanceof HelpArticleFeedback && $this->feedbackValue === '') {
            $this->feedbackValue = $current->value->value;
            $this->feedbackReason = $current->reason?->value ?? '';
        }

        return view('livewire.help-center.article', [
            'article' => $article,
            'feedbackReasons' => collect(HelpFeedbackReason::cases())->map(fn (HelpFeedbackReason $reason): array => ['value' => $reason->value, 'label' => $reason->label()])->all(),
            'reportReasons' => collect(HelpReportReason::cases())->map(fn (HelpReportReason $reason): array => ['value' => $reason->value, 'label' => $reason->label()])->all(),
        ])->extends('layouts.app', [
            'title' => $article->title,
            'seo' => $seo->article($article),
        ])->section('content');
    }
}
