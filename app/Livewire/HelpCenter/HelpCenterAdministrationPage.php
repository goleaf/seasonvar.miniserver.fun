<?php

declare(strict_types=1);

namespace App\Livewire\HelpCenter;

use App\Actions\HelpCenter\MarkHelpArticleReviewed;
use App\Actions\HelpCenter\MergeHelpArticle;
use App\Actions\HelpCenter\RestoreHelpArticleRevision;
use App\Actions\HelpCenter\ReviewHelpArticleReport;
use App\Actions\HelpCenter\SaveHelpArticle;
use App\Actions\HelpCenter\SaveHelpCategory;
use App\Actions\HelpCenter\SyncHelpArticleRelations;
use App\Actions\HelpCenter\TransitionHelpArticle;
use App\Enums\ContentRequestType;
use App\Enums\HelpArticleType;
use App\Enums\HelpAudience;
use App\Enums\HelpEscalationType;
use App\Enums\HelpFeature;
use App\Enums\HelpOwnerTeam;
use App\Enums\HelpPublicationStatus;
use App\Enums\TechnicalIssueType;
use App\Models\HelpArticle;
use App\Models\HelpArticleFeedback;
use App\Models\HelpArticleReport;
use App\Models\HelpArticleRevision;
use App\Models\HelpCategory;
use App\Models\User;
use App\Services\HelpCenter\HelpCenterSchema;
use App\Services\HelpCenter\HelpLocale;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class HelpCenterAdministrationPage extends Component
{
    use WithPagination;

    #[Url(history: true, except: 'articles')]
    public string $tab = 'articles';

    #[Url(history: true, except: '')]
    public string $statusFilter = '';

    #[Url(history: true, except: 'ru')]
    public string $localeFilter = 'ru';

    #[Url(history: true, except: 'all')]
    public string $reviewFilter = 'all';

    #[Locked]
    public int $articleId = 0;

    public string $code = '';

    public string $categoryId = '';

    public string $type = 'feature_guide';

    public string $audience = 'everyone';

    public string $ownerTeam = 'support';

    public string $featureCode = 'general';

    public string $primaryEscalation = 'none';

    public string $secondaryEscalation = 'none';

    public string $issueType = '';

    public string $requestType = '';

    public int $position = 0;

    public int $editorialPriority = 50;

    public bool $featured = false;

    public bool $indexable = true;

    public string $locale = 'ru';

    public string $slug = '';

    public string $title = '';

    public string $summary = '';

    public string $bodyMarkdown = '';

    public string $keywords = '';

    public string $aliases = '';

    public string $seoTitle = '';

    public string $seoDescription = '';

    public string $calloutType = '';

    public string $calloutText = '';

    public string $changeNote = '';

    public string $relatedCodes = '';

    public string $contextualCodes = '';

    public string $replacementCode = '';

    #[Locked]
    public int $categoryEditId = 0;

    public string $categoryCode = '';

    public string $categoryParentId = '';

    public int $categoryPosition = 0;

    public bool $categoryVisible = true;

    public string $categoryRuSlug = '';

    public string $categoryRuTitle = '';

    public string $categoryRuDescription = '';

    public string $categoryEnSlug = '';

    public string $categoryEnTitle = '';

    public string $categoryEnDescription = '';

    /** @var array<int, string> */
    public array $reportPrivateNotes = [];

    public ?string $statusMessage = null;

    public function mount(HelpCenterSchema $schema, HelpLocale $locales): void
    {
        abort_unless($schema->ready(), 404);
        $this->authorizeManager();
        $this->localeFilter = $locales->normalize($this->localeFilter);
        $this->locale = $this->localeFilter;
    }

    public function newArticle(): void
    {
        $this->authorizeManager();
        $this->resetArticleForm();
    }

    public function updatedTab(): void
    {
        $this->resetPage('helpArticlesPage');
        $this->resetPage('helpFeedbackPage');
        $this->resetPage('helpReportsPage');
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage('helpArticlesPage');
    }

    public function updatedLocaleFilter(): void
    {
        $this->resetPage('helpArticlesPage');
    }

    public function updatedReviewFilter(): void
    {
        $this->resetPage('helpArticlesPage');
    }

    public function editArticle(int $articleId, string $locale, HelpLocale $locales): void
    {
        $this->authorizeManager();
        $locale = $locales->normalize($locale);
        $article = HelpArticle::query()
            ->with(['translations', 'aliases', 'replacement:id,code'])
            ->findOrFail($articleId);
        $translation = $article->translations->firstWhere('locale', $locale);
        $this->articleId = $article->id;
        $this->code = $article->code;
        $this->categoryId = (string) $article->help_category_id;
        $this->type = $article->type->value;
        $this->audience = $article->audience->value;
        $this->ownerTeam = $article->owner_team->value;
        $this->featureCode = $article->feature_code->value;
        $this->primaryEscalation = $article->primary_escalation->value;
        $this->secondaryEscalation = $article->secondary_escalation->value;
        $this->issueType = (string) $article->escalation_issue_type;
        $this->requestType = (string) $article->escalation_request_type;
        $this->position = $article->position;
        $this->editorialPriority = $article->editorial_priority;
        $this->featured = $article->is_featured;
        $this->indexable = $article->is_indexable;
        $this->locale = $locale;
        $this->slug = (string) $translation?->slug;
        $this->title = (string) $translation?->title;
        $this->summary = (string) $translation?->summary;
        $this->bodyMarkdown = (string) $translation?->body_markdown;
        $this->keywords = (string) $translation?->keywords;
        $this->aliases = $article->aliases->where('locale', $locale)->sortByDesc('priority')->pluck('alias')->implode("\n");
        $this->seoTitle = (string) $translation?->seo_title;
        $this->seoDescription = (string) $translation?->seo_description;
        $this->calloutType = (string) $translation?->callout_type;
        $this->calloutText = (string) $translation?->callout_text;
        $this->changeNote = '';
        $relatedIds = DB::table('help_article_relations')
            ->where('help_article_id', $article->id)
            ->orderBy('position')
            ->orderBy('related_article_id')
            ->pluck('related_article_id');
        $relatedById = HelpArticle::query()->whereKey($relatedIds)->pluck('code', 'id');
        $this->relatedCodes = $relatedIds->map(fn (int $id): ?string => $relatedById->get($id))->filter()->implode(', ');
        $this->contextualCodes = DB::table('help_contextual_links')
            ->where('help_article_id', $article->id)
            ->orderBy('position')
            ->get(['feature_code', 'context_code'])
            ->map(fn (object $row): string => $row->feature_code.':'.$row->context_code)
            ->implode("\n");
        $this->replacementCode = (string) $article->replacement?->code;
    }

    public function saveArticle(SaveHelpArticle $save, SyncHelpArticleRelations $relations): void
    {
        $user = $this->manager();
        $article = $this->articleId > 0 ? HelpArticle::query()->findOrFail($this->articleId) : null;
        $saved = DB::transaction(function () use ($save, $relations, $user, $article): HelpArticle {
            $saved = $save->handle($user, [
                'code' => $this->code,
                'category_id' => $this->categoryId,
                'type' => $this->type,
                'audience' => $this->audience,
                'owner_team' => $this->ownerTeam,
                'feature_code' => $this->featureCode,
                'primary_escalation' => $this->primaryEscalation,
                'secondary_escalation' => $this->secondaryEscalation,
                'escalation_issue_type' => $this->issueType,
                'escalation_request_type' => $this->requestType,
                'position' => $this->position,
                'editorial_priority' => $this->editorialPriority,
                'is_featured' => $this->featured,
                'is_indexable' => $this->indexable,
                'locale' => $this->locale,
                'slug' => $this->slug,
                'title' => $this->title,
                'summary' => $this->summary,
                'body_markdown' => $this->bodyMarkdown,
                'keywords' => $this->keywords,
                'aliases' => $this->aliases,
                'seo_title' => $this->seoTitle,
                'seo_description' => $this->seoDescription,
                'callout_type' => $this->calloutType,
                'callout_text' => $this->calloutText,
                'change_note' => $this->changeNote,
            ], $article);
            $relations->handle($user, $saved, $this->relatedCodes, $this->contextualCodes, $this->replacementCode);

            return $saved;
        }, attempts: 3);
        $this->articleId = $saved->id;
        $this->statusMessage = __('help.admin.messages.saved');
        $this->editArticle($saved->id, $this->locale, app(HelpLocale::class));
    }

    public function switchLocale(string $locale, HelpLocale $locales): void
    {
        $this->locale = $locales->normalize($locale);

        if ($this->articleId > 0) {
            $this->editArticle($this->articleId, $this->locale, $locales);
        }
    }

    public function transitionArticle(string $target, TransitionHelpArticle $action): void
    {
        $article = HelpArticle::query()->findOrFail($this->articleId);
        $status = HelpPublicationStatus::tryFrom($target);
        abort_unless($status instanceof HelpPublicationStatus, 422);
        $action->handle($this->manager(), $article, $status, $this->locale, $this->changeNote !== '' ? $this->changeNote : null);
        $this->statusMessage = __('help.admin.messages.transitioned');
    }

    public function markReviewed(MarkHelpArticleReviewed $action): void
    {
        $action->handle($this->manager(), HelpArticle::query()->findOrFail($this->articleId));
        $this->statusMessage = __('help.admin.messages.reviewed');
    }

    public function mergeArticle(MergeHelpArticle $action): void
    {
        $source = HelpArticle::query()->findOrFail($this->articleId);
        $target = $action->handle($this->manager(), $source, $this->replacementCode);
        $this->statusMessage = __('help.admin.messages.merged', ['code' => $target->code]);
        $this->editArticle($target->id, $this->locale, app(HelpLocale::class));
    }

    public function restoreRevision(int $revisionId, RestoreHelpArticleRevision $action): void
    {
        $article = HelpArticle::query()->findOrFail($this->articleId);
        $revision = HelpArticleRevision::query()->findOrFail($revisionId);
        $action->handle($this->manager(), $article, $revision);
        $this->statusMessage = __('help.admin.messages.saved');
        $this->editArticle($article->id, $revision->locale, app(HelpLocale::class));
    }

    public function newCategory(): void
    {
        $this->categoryEditId = 0;
        $this->reset(
            'categoryCode', 'categoryParentId', 'categoryPosition', 'categoryRuSlug', 'categoryRuTitle',
            'categoryRuDescription', 'categoryEnSlug', 'categoryEnTitle', 'categoryEnDescription',
        );
        $this->categoryVisible = true;
    }

    public function editCategory(int $categoryId): void
    {
        $this->authorizeManager();
        $category = HelpCategory::query()->with('translations')->findOrFail($categoryId);
        $ru = $category->translations->firstWhere('locale', 'ru');
        $en = $category->translations->firstWhere('locale', 'en');
        $this->categoryEditId = $category->id;
        $this->categoryCode = $category->code;
        $this->categoryParentId = (string) $category->parent_id;
        $this->categoryPosition = $category->position;
        $this->categoryVisible = $category->is_visible;
        $this->categoryRuSlug = (string) $ru?->slug;
        $this->categoryRuTitle = (string) $ru?->title;
        $this->categoryRuDescription = (string) $ru?->description;
        $this->categoryEnSlug = (string) $en?->slug;
        $this->categoryEnTitle = (string) $en?->title;
        $this->categoryEnDescription = (string) $en?->description;
    }

    public function saveCategory(SaveHelpCategory $action): void
    {
        $category = $this->categoryEditId > 0 ? HelpCategory::query()->findOrFail($this->categoryEditId) : null;
        $saved = $action->handle($this->manager(), [
            'code' => $this->categoryCode,
            'parent_id' => $this->categoryParentId !== '' ? $this->categoryParentId : null,
            'position' => $this->categoryPosition,
            'is_visible' => $this->categoryVisible,
            'translations' => [
                ['locale' => 'ru', 'slug' => $this->categoryRuSlug, 'title' => $this->categoryRuTitle, 'description' => $this->categoryRuDescription],
                ['locale' => 'en', 'slug' => $this->categoryEnSlug, 'title' => $this->categoryEnTitle, 'description' => $this->categoryEnDescription],
            ],
        ], $category);
        $this->categoryEditId = $saved->id;
        $this->statusMessage = __('help.admin.messages.saved');
    }

    public function reviewReport(int $reportId, string $status, ReviewHelpArticleReport $action): void
    {
        $action->handle(
            $this->manager(),
            HelpArticleReport::query()->findOrFail($reportId),
            $status,
            $this->reportPrivateNotes[$reportId] ?? null,
        );
        unset($this->reportPrivateNotes[$reportId]);
        $this->statusMessage = __('help.admin.messages.report_reviewed');
    }

    public function render(HelpCenterSchema $schema, HelpLocale $locales): View
    {
        abort_unless($schema->ready(), 404);
        $this->authorizeManager();
        $this->tab = in_array($this->tab, ['articles', 'categories', 'feedback', 'reports'], true) ? $this->tab : 'articles';
        $this->statusFilter = HelpPublicationStatus::tryFrom($this->statusFilter)?->value ?? '';
        $this->localeFilter = $locales->normalize($this->localeFilter);
        $this->reviewFilter = in_array($this->reviewFilter, ['all', 'due', 'broken'], true) ? $this->reviewFilter : 'all';
        $articles = HelpArticle::query()
            ->with([
                'category:id,code',
                'translations' => fn ($query) => $query->where('locale', $this->localeFilter),
            ])
            ->withCount(['revisions', 'feedback', 'reports as open_reports_count' => fn (Builder $query) => $query->where('status', 'open')])
            ->when($this->statusFilter !== '', fn (Builder $query): Builder => $query->where('status', $this->statusFilter))
            ->when($this->reviewFilter === 'due', fn (Builder $query): Builder => $query->whereNotNull('review_due_at')->where('review_due_at', '<=', now()))
            ->when($this->reviewFilter === 'broken', fn (Builder $query): Builder => $query->whereHas('translations', fn (Builder $translation): Builder => $translation->where('link_status', 'broken')))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(30, pageName: 'helpArticlesPage');
        $categories = HelpCategory::query()->with('translations')->withCount('articles')->orderBy('position')->orderBy('id')->get();
        $revisions = $this->articleId > 0
            ? HelpArticleRevision::query()->where('help_article_id', $this->articleId)->where('locale', $this->locale)->latest('revision')->limit(20)->get()
            : collect();
        $reports = HelpArticleReport::query()
            ->with(['article:id,public_id,code', 'translation:id,help_article_id,locale,title'])
            ->where('status', 'open')
            ->oldest('created_at')
            ->paginate(30, pageName: 'helpReportsPage');
        $feedback = HelpArticleFeedback::query()
            ->select(['help_article_translation_id', 'value', DB::raw('count(*) as responses_count')])
            ->with('translation:id,help_article_id,locale,title')
            ->groupBy('help_article_translation_id', 'value')
            ->orderByDesc('responses_count')
            ->paginate(50, pageName: 'helpFeedbackPage');

        $selectedArticle = $this->articleId > 0
            ? HelpArticle::query()->with(['translations' => fn ($query) => $query->where('locale', $this->locale)])->find($this->articleId)
            : null;

        return view('livewire.help-center.administration', [
            'articles' => $articles,
            'categories' => $categories,
            'revisions' => $revisions,
            'reports' => $reports,
            'feedbackAggregates' => $feedback,
            'statusOptions' => $this->options(HelpPublicationStatus::cases()),
            'typeOptions' => $this->options(HelpArticleType::cases()),
            'audienceOptions' => $this->options(HelpAudience::cases()),
            'ownerOptions' => $this->options(HelpOwnerTeam::cases()),
            'featureOptions' => collect(HelpFeature::cases())->map(fn (HelpFeature $feature): array => ['value' => $feature->value, 'label' => $feature->label()])->all(),
            'escalationOptions' => $this->options(HelpEscalationType::cases()),
            'issueTypeOptions' => collect((array) config('help-center.allowed_issue_types', []))->map(fn (string $value): array => [
                'value' => $value,
                'label' => TechnicalIssueType::from($value)->label(),
            ])->all(),
            'requestTypeOptions' => collect((array) config('help-center.allowed_request_types', []))->map(fn (string $value): array => [
                'value' => $value,
                'label' => ContentRequestType::from($value)->label(),
            ])->all(),
            'calloutOptions' => (array) config('help-center.allowed_callouts', []),
            'selectedArticle' => $selectedArticle,
            'selectedTranslation' => $selectedArticle?->translations->first(),
            'previewUrl' => $selectedArticle instanceof HelpArticle ? route('admin.help.preview', ['helpArticle' => $selectedArticle, 'locale' => $this->locale]) : null,
        ])->extends('layouts.app', [
            'title' => __('help.admin.title'),
            'seo' => ['robots' => 'noindex, nofollow', 'social' => false, 'alternates' => []],
        ])->section('content');
    }

    private function authorizeManager(): void
    {
        Gate::authorize('manage-help-center');
    }

    private function manager(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        Gate::forUser($user)->authorize('manage-help-center');

        return $user;
    }

    private function resetArticleForm(): void
    {
        $this->articleId = 0;
        $this->reset(
            'code', 'categoryId', 'issueType', 'requestType', 'slug', 'title', 'summary',
            'bodyMarkdown', 'keywords', 'aliases', 'seoTitle', 'seoDescription', 'calloutType',
            'calloutText', 'changeNote', 'relatedCodes', 'contextualCodes', 'replacementCode',
        );
        $this->type = HelpArticleType::FeatureGuide->value;
        $this->audience = HelpAudience::Everyone->value;
        $this->ownerTeam = HelpOwnerTeam::Support->value;
        $this->featureCode = HelpFeature::General->value;
        $this->primaryEscalation = HelpEscalationType::None->value;
        $this->secondaryEscalation = HelpEscalationType::None->value;
        $this->position = 0;
        $this->editorialPriority = 50;
        $this->featured = false;
        $this->indexable = true;
        $this->locale = $this->localeFilter;
    }

    /** @param array<int, HelpPublicationStatus|HelpArticleType|HelpAudience|HelpOwnerTeam|HelpEscalationType> $cases
     * @return list<array{value: string, label: string}>
     */
    private function options(array $cases): array
    {
        return array_map(static fn ($case): array => ['value' => $case->value, 'label' => $case->label()], $cases);
    }
}
