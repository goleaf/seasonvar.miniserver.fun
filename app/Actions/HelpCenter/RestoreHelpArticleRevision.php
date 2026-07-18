<?php

declare(strict_types=1);

namespace App\Actions\HelpCenter;

use App\Enums\HelpPublicationStatus;
use App\Models\HelpArticle;
use App\Models\HelpArticleRevision;
use App\Models\HelpArticleSlug;
use App\Models\HelpArticleTranslation;
use App\Models\User;
use App\Services\Catalog\Search\CatalogSearchNormalizer;
use App\Services\HelpCenter\HelpCacheInvalidator;
use App\Services\HelpCenter\HelpLinkValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class RestoreHelpArticleRevision
{
    public function __construct(
        private CatalogSearchNormalizer $normalizer,
        private HelpLinkValidator $links,
        private HelpCacheInvalidator $cache,
    ) {}

    public function handle(User $editor, HelpArticle $article, HelpArticleRevision $revision): HelpArticle
    {
        Gate::forUser($editor)->authorize('restoreRevision', $article);
        abort_unless($revision->help_article_id === $article->id, 404);

        $saved = DB::transaction(function () use ($editor, $article, $revision): HelpArticle {
            $locked = HelpArticle::query()->lockForUpdate()->findOrFail($article->id);
            $translation = HelpArticleTranslation::query()
                ->where('help_article_id', $locked->id)
                ->where('locale', $revision->locale)
                ->lockForUpdate()
                ->firstOrFail();
            $slugInUse = HelpArticleTranslation::query()
                ->where('locale', $revision->locale)
                ->where('slug', $revision->slug)
                ->where('help_article_id', '!=', $locked->id)
                ->exists()
                || HelpArticleSlug::query()
                    ->where('locale', $revision->locale)
                    ->where('slug', $revision->slug)
                    ->where('help_article_id', '!=', $locked->id)
                    ->exists();

            if ($slugInUse) {
                throw ValidationException::withMessages(['slug' => [__('help.admin.validation.slug_unique')]]);
            }

            HelpArticleTranslation::query()
                ->where('help_article_id', $locked->id)
                ->where('is_published', true)
                ->update(['is_published' => false]);
            $oldSlug = $translation->slug;
            $errors = $this->links->validate($revision->body_markdown);
            $translation->fill([
                'slug' => $revision->slug,
                'title' => $revision->title,
                'summary' => $revision->summary,
                'body_markdown' => $revision->body_markdown,
                'search_text' => $this->normalizer->key(implode(' ', [$revision->title, $revision->summary, $revision->keywords, $revision->body_markdown])),
                'keywords' => $revision->keywords,
                'seo_title' => $revision->seo_title,
                'seo_description' => $revision->seo_description,
                'callout_text' => $revision->callout_text,
                'callout_type' => $revision->callout_type,
                'is_published' => false,
                'links_checked_at' => now(),
                'link_status' => $errors === [] ? 'valid' : 'broken',
                'link_errors' => $errors === [] ? null : $errors,
            ])->save();

            if ($oldSlug !== $translation->slug) {
                HelpArticleSlug::query()->firstOrCreate(
                    ['locale' => $translation->locale, 'slug' => $oldSlug],
                    ['help_article_id' => $locked->id],
                );
            }

            $locked->forceFill([
                'status' => HelpPublicationStatus::Draft,
                'updated_by_id' => $editor->id,
                'content_version' => $locked->content_version + 1,
            ])->save();
            $next = (int) HelpArticleRevision::query()
                ->where('help_article_id', $locked->id)
                ->where('locale', $translation->locale)
                ->max('revision') + 1;
            HelpArticleRevision::query()->create([
                'public_id' => (string) Str::uuid(),
                'help_article_id' => $locked->id,
                'editor_id' => $editor->id,
                'locale' => $translation->locale,
                'revision' => $next,
                'article_status' => HelpPublicationStatus::Draft,
                'translation_published' => false,
                'slug' => $translation->slug,
                'title' => $translation->title,
                'summary' => $translation->summary,
                'body_markdown' => $translation->body_markdown,
                'keywords' => $translation->keywords,
                'seo_title' => $translation->seo_title,
                'seo_description' => $translation->seo_description,
                'callout_text' => $translation->callout_text,
                'callout_type' => $translation->callout_type,
                'change_note' => __('help.admin.revisions.restored_note', ['revision' => $revision->revision]),
            ]);

            return $locked->fresh();
        }, attempts: 3);

        $this->cache->changed($saved->public_id);

        return $saved;
    }
}
