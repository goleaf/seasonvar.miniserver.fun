<?php

declare(strict_types=1);

namespace App\Actions\HelpCenter;

use App\Enums\HelpArticleType;
use App\Enums\HelpAudience;
use App\Enums\HelpEscalationType;
use App\Enums\HelpFeature;
use App\Enums\HelpOwnerTeam;
use App\Enums\HelpPublicationStatus;
use App\Models\HelpArticle;
use App\Models\HelpArticleAlias;
use App\Models\HelpArticleRevision;
use App\Models\HelpArticleSlug;
use App\Models\HelpArticleTranslation;
use App\Models\HelpCategory;
use App\Models\User;
use App\Services\Catalog\Search\CatalogSearchNormalizer;
use App\Services\HelpCenter\HelpArticleRenderer;
use App\Services\HelpCenter\HelpCacheInvalidator;
use App\Services\HelpCenter\HelpLinkValidator;
use App\Services\HelpCenter\HelpLocale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class SaveHelpArticle
{
    public function __construct(
        private HelpLocale $locales,
        private CatalogSearchNormalizer $normalizer,
        private HelpLinkValidator $links,
        private HelpArticleRenderer $renderer,
        private HelpCacheInvalidator $cache,
    ) {}

    /** @param array<string, mixed> $input */
    public function handle(User $editor, array $input, ?HelpArticle $article = null): HelpArticle
    {
        $article === null
            ? Gate::forUser($editor)->authorize('create', HelpArticle::class)
            : Gate::forUser($editor)->authorize('update', $article);
        foreach (['escalation_issue_type', 'escalation_request_type', 'callout_type'] as $nullableCode) {
            if (($input[$nullableCode] ?? null) === '') {
                $input[$nullableCode] = null;
            }
        }

        $locale = $this->locales->normalize(is_string($input['locale'] ?? null) ? $input['locale'] : null);
        $data = validator($input, $this->rules($article, $locale), $this->messages())->validate();
        $historicalOwner = HelpArticleSlug::query()
            ->where('locale', $locale)
            ->where('slug', $data['slug'])
            ->value('help_article_id');

        if (is_numeric($historicalOwner) && (int) $historicalOwner !== $article?->id) {
            throw ValidationException::withMessages(['slug' => [__('help.admin.validation.slug_unique')]]);
        }

        $category = HelpCategory::query()->findOrFail((int) $data['category_id']);
        $this->assertCategoryDepth($category);
        $articleType = HelpArticleType::from($data['type']);

        if (! $articleType->categoryEligible($category->code)) {
            throw ValidationException::withMessages(['category_id' => [__('help.admin.validation.category_type')]]);
        }

        $markdown = str_replace(["\r\n", "\r"], "\n", trim((string) $data['body_markdown']));
        $linkErrors = $this->links->validate($markdown);
        $rendered = $this->renderer->render($markdown, $locale);

        if (trim(strip_tags($rendered->html)) === '') {
            throw ValidationException::withMessages(['body_markdown' => [__('help.admin.validation.body_required')]]);
        }

        $didChange = false;
        $saved = DB::transaction(function () use ($article, $category, $data, $editor, $locale, $markdown, $linkErrors, &$didChange): HelpArticle {
            $locked = $article instanceof HelpArticle
                ? HelpArticle::query()->lockForUpdate()->findOrFail($article->id)
                : new HelpArticle(['public_id' => (string) Str::uuid(), 'created_by_id' => $editor->id]);
            $isNew = ! $locked->exists;
            $locked->fill([
                'code' => $data['code'],
                'help_category_id' => $category->id,
                'type' => $data['type'],
                'audience' => $data['audience'],
                'owner_team' => $data['owner_team'],
                'feature_code' => $data['feature_code'],
                'primary_escalation' => $data['primary_escalation'],
                'secondary_escalation' => $data['secondary_escalation'],
                'escalation_issue_type' => $data['escalation_issue_type'] ?: null,
                'escalation_request_type' => $data['escalation_request_type'] ?: null,
                'position' => (int) $data['position'],
                'editorial_priority' => (int) $data['editorial_priority'],
                'is_featured' => (bool) $data['is_featured'],
                'is_indexable' => (bool) $data['is_indexable'],
            ]);

            if ($isNew) {
                $locked->status = HelpPublicationStatus::Draft;
            }

            $articleChanged = $isNew || $locked->isDirty();

            if ($isNew) {
                $locked->updated_by_id = $editor->id;
                $locked->content_version = 1;
                $locked->save();
            }

            $translation = HelpArticleTranslation::query()
                ->where('help_article_id', $locked->id)
                ->where('locale', $locale)
                ->lockForUpdate()
                ->first();
            $oldSlug = $translation?->slug;
            $translation ??= new HelpArticleTranslation([
                'help_article_id' => $locked->id,
                'locale' => $locale,
                'is_published' => false,
            ]);
            $translation->fill([
                'slug' => $data['slug'],
                'title' => $data['title'],
                'summary' => $data['summary'],
                'body_markdown' => $markdown,
                'search_text' => $this->searchText($data, $markdown),
                'keywords' => $data['keywords'] ?: null,
                'seo_title' => $data['seo_title'] ?: null,
                'seo_description' => $data['seo_description'] ?: null,
                'callout_text' => $data['callout_text'] ?: null,
                'callout_type' => $data['callout_type'] ?: null,
            ]);
            $translationChanged = ! $translation->exists || $translation->isDirty();
            $linkStatus = $linkErrors === [] ? 'valid' : 'broken';
            $linkErrorsValue = $linkErrors === [] ? null : $linkErrors;
            $linkStateChanged = ! $translation->exists
                || $translation->link_status !== $linkStatus
                || $translation->link_errors !== $linkErrorsValue;
            $aliasesChanged = $this->aliasesDiffer($locked, $locale, (string) $data['aliases']);

            if (! $isNew
                && $locked->status === HelpPublicationStatus::Published
                && ($articleChanged
                    || ($translation->exists && $translation->is_published && ($translationChanged || $aliasesChanged)))) {
                throw ValidationException::withMessages(['article' => [__('help.admin.validation.published_edit')]]);
            }

            if ($translationChanged || $linkStateChanged) {
                $translation->forceFill([
                    'links_checked_at' => now(),
                    'link_status' => $linkStatus,
                    'link_errors' => $linkErrorsValue,
                ]);
                $translation->save();
            } else {
                DB::table('help_article_translations')->where('id', $translation->id)->update(['links_checked_at' => now()]);
            }

            if (is_string($oldSlug) && $oldSlug !== $translation->slug) {
                HelpArticleSlug::query()->firstOrCreate([
                    'locale' => $locale,
                    'slug' => $oldSlug,
                ], ['help_article_id' => $locked->id]);
            }

            if ($aliasesChanged) {
                $this->syncAliases($locked, $locale, (string) $data['aliases']);
            }
            $changed = $articleChanged || $translationChanged || $aliasesChanged;
            $didChange = $changed;

            if (! $isNew && $changed) {
                $locked->updated_by_id = $editor->id;
                $locked->content_version = max(1, (int) $locked->content_version + 1);
                $locked->save();
            }

            if ($changed && ! $this->hasMatchingRevision($locked, $translation)) {
                $this->revision($locked, $translation, $editor, (string) ($data['change_note'] ?? ''));
            }

            return $locked->fresh(['translations', 'category.translations']);
        }, attempts: 3);

        if ($didChange) {
            $this->cache->changed($saved->public_id);
        }

        return $saved;
    }

    /** @return array<string, list<mixed>> */
    private function rules(?HelpArticle $article, string $locale): array
    {
        $translationId = $article instanceof HelpArticle
            ? $article->translations()->where('locale', $locale)->value('id')
            : null;

        return [
            'code' => ['required', 'string', 'max:96', 'regex:/^[a-z0-9][a-z0-9-]+$/', Rule::unique('help_articles', 'code')->ignore($article?->id)],
            'category_id' => ['required', 'integer', 'exists:help_categories,id'],
            'type' => ['required', Rule::enum(HelpArticleType::class)],
            'audience' => ['required', Rule::enum(HelpAudience::class)],
            'owner_team' => ['required', Rule::enum(HelpOwnerTeam::class)],
            'feature_code' => ['required', Rule::enum(HelpFeature::class)],
            'primary_escalation' => ['required', Rule::enum(HelpEscalationType::class)],
            'secondary_escalation' => ['required', Rule::enum(HelpEscalationType::class)],
            'escalation_issue_type' => ['nullable', 'string', Rule::in((array) config('help-center.allowed_issue_types', []))],
            'escalation_request_type' => ['nullable', 'string', Rule::in((array) config('help-center.allowed_request_types', []))],
            'position' => ['required', 'integer', 'min:0', 'max:65535'],
            'editorial_priority' => ['required', 'integer', 'min:0', 'max:100'],
            'is_featured' => ['required', 'boolean'],
            'is_indexable' => ['required', 'boolean'],
            'locale' => ['required', Rule::in($this->locales->supported())],
            'slug' => ['required', 'string', 'max:180', 'regex:/^[\pL\pN][\pL\pN-]*$/u', Rule::unique('help_article_translations', 'slug')->where('locale', $locale)->ignore($translationId)],
            'title' => ['required', 'string', 'max:220'],
            'summary' => ['required', 'string', 'max:700'],
            'body_markdown' => ['required', 'string', 'max:60000'],
            'keywords' => ['nullable', 'string', 'max:2000'],
            'aliases' => ['nullable', 'string', 'max:4000'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'callout_text' => ['nullable', 'string', 'max:500'],
            'callout_type' => ['nullable', Rule::in((array) config('help-center.allowed_callouts', []))],
            'change_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'code.regex' => __('help.admin.validation.code'),
            'slug.regex' => __('help.admin.validation.slug'),
            'slug.unique' => __('help.admin.validation.slug_unique'),
            '*.required' => __('help.admin.validation.required'),
        ];
    }

    private function assertCategoryDepth(HelpCategory $category): void
    {
        if ($category->parent_id !== null && HelpCategory::query()->whereKey($category->parent_id)->whereNotNull('parent_id')->exists()) {
            throw ValidationException::withMessages(['category_id' => [__('help.admin.validation.category_depth')]]);
        }
    }

    /** @param array<string, mixed> $data */
    private function searchText(array $data, string $markdown): string
    {
        return mb_substr($this->normalizer->key(implode(' ', [
            $data['title'], $data['summary'], $data['keywords'] ?? '', strip_tags($markdown),
        ])), 0, 60000);
    }

    private function aliasesDiffer(HelpArticle $article, string $locale, string $aliases): bool
    {
        $values = $this->aliasValues($aliases);
        $current = HelpArticleAlias::query()
            ->where('help_article_id', $article->id)
            ->where('locale', $locale)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->pluck('alias')
            ->values();

        return $current->all() !== $values->all();
    }

    private function syncAliases(HelpArticle $article, string $locale, string $aliases): void
    {
        $values = $this->aliasValues($aliases);

        HelpArticleAlias::query()->where('help_article_id', $article->id)->where('locale', $locale)->delete();

        foreach ($values as $position => $alias) {
            HelpArticleAlias::query()->create([
                'help_article_id' => $article->id,
                'locale' => $locale,
                'alias' => $alias,
                'normalized_alias' => $this->normalizer->key($alias),
                'priority' => max(0, 100 - $position),
            ]);
        }

    }

    /** @return Collection<int, non-falsy-string> */
    private function aliasValues(string $aliases): Collection
    {
        return collect(preg_split('/[\r\n]+/u', $aliases) ?: [])
            ->map(fn (string $value): string => mb_substr($this->normalizer->display($value), 0, 220))
            ->filter()
            ->unique(fn (string $value): string => $this->normalizer->key($value))
            ->take(40)
            ->values();
    }

    private function hasMatchingRevision(HelpArticle $article, HelpArticleTranslation $translation): bool
    {
        $latest = HelpArticleRevision::query()
            ->where('help_article_id', $article->id)
            ->where('locale', $translation->locale)
            ->latest('revision')
            ->first();

        return $latest instanceof HelpArticleRevision
            && $latest->article_status === $article->status
            && $latest->translation_published === $translation->is_published
            && $latest->slug === $translation->slug
            && $latest->title === $translation->title
            && $latest->summary === $translation->summary
            && $latest->body_markdown === $translation->body_markdown
            && $latest->keywords === $translation->keywords
            && $latest->seo_title === $translation->seo_title
            && $latest->seo_description === $translation->seo_description
            && $latest->callout_text === $translation->callout_text
            && $latest->callout_type === $translation->callout_type;
    }

    private function revision(HelpArticle $article, HelpArticleTranslation $translation, User $editor, string $note): void
    {
        $revision = (int) HelpArticleRevision::query()
            ->where('help_article_id', $article->id)
            ->where('locale', $translation->locale)
            ->max('revision') + 1;
        HelpArticleRevision::query()->create([
            'public_id' => (string) Str::uuid(),
            'help_article_id' => $article->id,
            'editor_id' => $editor->id,
            'locale' => $translation->locale,
            'revision' => $revision,
            'article_status' => $article->status,
            'translation_published' => $translation->is_published,
            'slug' => $translation->slug,
            'title' => $translation->title,
            'summary' => $translation->summary,
            'body_markdown' => $translation->body_markdown,
            'keywords' => $translation->keywords,
            'seo_title' => $translation->seo_title,
            'seo_description' => $translation->seo_description,
            'callout_text' => $translation->callout_text,
            'callout_type' => $translation->callout_type,
            'change_note' => $note !== '' ? $note : null,
        ]);
    }
}
