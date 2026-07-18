<?php

declare(strict_types=1);

namespace App\Actions\HelpCenter;

use App\Enums\HelpPublicationStatus;
use App\Models\HelpArticle;
use App\Models\HelpArticleRevision;
use App\Models\HelpArticleTranslation;
use App\Models\User;
use App\Services\HelpCenter\HelpCacheInvalidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class TransitionHelpArticle
{
    public function __construct(private HelpCacheInvalidator $cache) {}

    public function handle(User $editor, HelpArticle $article, HelpPublicationStatus $target, string $locale, ?string $note = null): HelpArticle
    {
        Gate::forUser($editor)->authorize('publish', $article);

        $saved = DB::transaction(function () use ($editor, $article, $target, $locale, $note): HelpArticle {
            $locked = HelpArticle::query()->lockForUpdate()->findOrFail($article->id);

            if (! in_array($target, $locked->status->allowedTransitions(), true)) {
                throw ValidationException::withMessages(['transition' => [__('help.admin.validation.transition')]]);
            }

            $translation = HelpArticleTranslation::query()
                ->where('help_article_id', $locked->id)
                ->where('locale', $locale)
                ->lockForUpdate()
                ->first();

            if ($target === HelpPublicationStatus::Published) {
                if (! $translation instanceof HelpArticleTranslation || $translation->link_status !== 'valid') {
                    throw ValidationException::withMessages(['transition' => [__('help.admin.validation.publish_translation')]]);
                }

                if ($locked->status === HelpPublicationStatus::Published && $translation->is_published) {
                    return $locked;
                }

                $translation->forceFill(['is_published' => true, 'published_at' => $translation->published_at ?? now()])->save();
                $locked->published_at ??= now();
                $locked->approved_by_id = $editor->id;
            }

            if (in_array($target, [HelpPublicationStatus::Approved, HelpPublicationStatus::Archived, HelpPublicationStatus::Hidden], true)
                && $locked->status === HelpPublicationStatus::Published) {
                HelpArticleTranslation::query()->where('help_article_id', $locked->id)->update(['is_published' => false]);
            }

            $locked->status = $target;
            $locked->updated_by_id = $editor->id;
            $locked->content_version++;
            $locked->save();

            if ($translation instanceof HelpArticleTranslation) {
                $revision = (int) HelpArticleRevision::query()
                    ->where('help_article_id', $locked->id)
                    ->where('locale', $locale)
                    ->max('revision') + 1;
                HelpArticleRevision::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'help_article_id' => $locked->id,
                    'editor_id' => $editor->id,
                    'locale' => $locale,
                    'revision' => $revision,
                    'article_status' => $target,
                    'translation_published' => $translation->fresh()->is_published,
                    'slug' => $translation->slug,
                    'title' => $translation->title,
                    'summary' => $translation->summary,
                    'body_markdown' => $translation->body_markdown,
                    'keywords' => $translation->keywords,
                    'seo_title' => $translation->seo_title,
                    'seo_description' => $translation->seo_description,
                    'callout_text' => $translation->callout_text,
                    'callout_type' => $translation->callout_type,
                    'change_note' => $note,
                ]);
            }

            return $locked->fresh();
        }, attempts: 3);

        $this->cache->changed($saved->public_id);

        return $saved;
    }
}
