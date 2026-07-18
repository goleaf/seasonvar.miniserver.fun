<?php

declare(strict_types=1);

namespace App\Actions\HelpCenter;

use App\Enums\HelpPublicationStatus;
use App\Models\HelpArticle;
use App\Models\HelpArticleAlias;
use App\Models\HelpArticleFeedback;
use App\Models\HelpArticleReport;
use App\Models\HelpArticleRevision;
use App\Models\HelpArticleTranslation;
use App\Models\HelpContextualLink;
use App\Models\User;
use App\Services\HelpCenter\HelpCacheInvalidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class MergeHelpArticle
{
    public function __construct(private HelpCacheInvalidator $cache) {}

    public function handle(User $editor, HelpArticle $source, string $targetCode): HelpArticle
    {
        Gate::forUser($editor)->authorize('update', $source);
        $target = HelpArticle::query()->where('code', trim($targetCode))->first();

        if (! $target instanceof HelpArticle || $target->id === $source->id) {
            throw ValidationException::withMessages(['replacementCode' => [__('help.admin.validation.merge_target')]]);
        }

        Gate::forUser($editor)->authorize('update', $target);

        $merged = DB::transaction(function () use ($editor, $source, $target): HelpArticle {
            $articles = HelpArticle::query()
                ->whereKey([$source->id, $target->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $lockedSource = $articles->get($source->id);
            $lockedTarget = $articles->get($target->id);

            if (! $lockedSource instanceof HelpArticle
                || ! $lockedTarget instanceof HelpArticle
                || $lockedSource->status === HelpPublicationStatus::Archived
                || $lockedTarget->status !== HelpPublicationStatus::Published
                || $lockedTarget->replacement_article_id !== null) {
                throw ValidationException::withMessages(['replacementCode' => [__('help.admin.validation.merge_target')]]);
            }

            $sourceTranslations = HelpArticleTranslation::query()
                ->where('help_article_id', $lockedSource->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $targetTranslations = HelpArticleTranslation::query()
                ->where('help_article_id', $lockedTarget->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('locale');

            foreach ($sourceTranslations as $sourceTranslation) {
                $this->snapshot(
                    $lockedSource,
                    $sourceTranslation,
                    $editor,
                    HelpPublicationStatus::Archived,
                    false,
                    __('help.admin.revisions.merged_source', ['code' => $lockedTarget->code]),
                );
                $targetTranslation = $targetTranslations->get($sourceTranslation->locale);

                if (! $targetTranslation instanceof HelpArticleTranslation) {
                    $sourceTranslation->forceFill([
                        'help_article_id' => $lockedTarget->id,
                        'is_published' => $sourceTranslation->is_published && $sourceTranslation->link_status === 'valid',
                    ])->save();
                    $targetTranslation = $sourceTranslation;
                    $targetTranslations->put($sourceTranslation->locale, $sourceTranslation);
                    $this->snapshot(
                        $lockedTarget,
                        $sourceTranslation,
                        $editor,
                        HelpPublicationStatus::Published,
                        $sourceTranslation->is_published,
                        __('help.admin.revisions.merged_target', ['code' => $lockedSource->code]),
                    );
                } else {
                    $duplicateActors = HelpArticleFeedback::query()
                        ->where('help_article_translation_id', $targetTranslation->id)
                        ->pluck('actor_key');
                    HelpArticleFeedback::query()
                        ->where('help_article_translation_id', $sourceTranslation->id)
                        ->when($duplicateActors->isNotEmpty(), fn ($query) => $query->whereIn('actor_key', $duplicateActors))
                        ->delete();
                    HelpArticleFeedback::query()
                        ->where('help_article_translation_id', $sourceTranslation->id)
                        ->update([
                            'help_article_id' => $lockedTarget->id,
                            'help_article_translation_id' => $targetTranslation->id,
                        ]);
                    HelpArticleReport::query()
                        ->where('help_article_translation_id', $sourceTranslation->id)
                        ->update([
                            'help_article_id' => $lockedTarget->id,
                            'help_article_translation_id' => $targetTranslation->id,
                        ]);
                    $sourceTranslation->forceFill(['is_published' => false])->save();
                }

                HelpArticleFeedback::query()
                    ->where('help_article_translation_id', $targetTranslation->id)
                    ->update(['help_article_id' => $lockedTarget->id]);
                HelpArticleReport::query()
                    ->where('help_article_translation_id', $targetTranslation->id)
                    ->update(['help_article_id' => $lockedTarget->id]);
            }

            $this->copyAliases($lockedSource, $lockedTarget);
            $this->moveContextualLinks($lockedSource, $lockedTarget);
            $this->moveRelations($lockedSource, $lockedTarget);

            HelpArticle::query()
                ->where('replacement_article_id', $lockedSource->id)
                ->whereKeyNot($lockedTarget->id)
                ->update(['replacement_article_id' => $lockedTarget->id]);

            if (Schema::hasTable('technical_issues') && Schema::hasColumn('technical_issues', 'help_article_id')) {
                DB::table('technical_issues')
                    ->where('help_article_id', $lockedSource->id)
                    ->update(['help_article_id' => $lockedTarget->id]);
            }

            $lockedSource->forceFill([
                'replacement_article_id' => $lockedTarget->id,
                'status' => HelpPublicationStatus::Archived,
                'is_featured' => false,
                'is_indexable' => false,
                'updated_by_id' => $editor->id,
                'content_version' => $lockedSource->content_version + 1,
            ])->save();
            $lockedTarget->forceFill([
                'updated_by_id' => $editor->id,
                'content_version' => $lockedTarget->content_version + 1,
            ])->save();

            return $lockedTarget->fresh();
        }, attempts: 3);

        $this->cache->changed($source->public_id);
        $this->cache->changed($merged->public_id);

        return $merged;
    }

    private function copyAliases(HelpArticle $source, HelpArticle $target): void
    {
        HelpArticleAlias::query()->where('help_article_id', $source->id)->orderBy('id')->each(
            function (HelpArticleAlias $alias) use ($target): void {
                HelpArticleAlias::query()->firstOrCreate([
                    'help_article_id' => $target->id,
                    'locale' => $alias->locale,
                    'normalized_alias' => $alias->normalized_alias,
                ], [
                    'alias' => $alias->alias,
                    'priority' => $alias->priority,
                ]);
            },
        );
    }

    private function moveContextualLinks(HelpArticle $source, HelpArticle $target): void
    {
        HelpContextualLink::query()->where('help_article_id', $source->id)->orderBy('id')->each(
            function (HelpContextualLink $link) use ($target): void {
                HelpContextualLink::query()->firstOrCreate([
                    'feature_code' => $link->feature_code->value,
                    'context_code' => $link->context_code,
                    'help_article_id' => $target->id,
                ], [
                    'position' => $link->position,
                    'is_active' => $link->is_active,
                ]);
            },
        );
        HelpContextualLink::query()->where('help_article_id', $source->id)->delete();
    }

    private function moveRelations(HelpArticle $source, HelpArticle $target): void
    {
        $outgoing = DB::table('help_article_relations')
            ->where('help_article_id', $source->id)
            ->whereNotIn('related_article_id', [$source->id, $target->id])
            ->orderBy('position')
            ->get();

        foreach ($outgoing as $row) {
            DB::table('help_article_relations')->insertOrIgnore([
                'help_article_id' => $target->id,
                'related_article_id' => $row->related_article_id,
                'position' => $row->position,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $incoming = DB::table('help_article_relations')
            ->where('related_article_id', $source->id)
            ->whereNotIn('help_article_id', [$source->id, $target->id])
            ->get();

        foreach ($incoming as $row) {
            DB::table('help_article_relations')->insertOrIgnore([
                'help_article_id' => $row->help_article_id,
                'related_article_id' => $target->id,
                'position' => $row->position,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('help_article_relations')
            ->where('help_article_id', $source->id)
            ->orWhere('related_article_id', $source->id)
            ->delete();
    }

    private function snapshot(
        HelpArticle $article,
        HelpArticleTranslation $translation,
        User $editor,
        HelpPublicationStatus $status,
        bool $published,
        string $note,
    ): void {
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
            'article_status' => $status,
            'translation_published' => $published,
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
}
