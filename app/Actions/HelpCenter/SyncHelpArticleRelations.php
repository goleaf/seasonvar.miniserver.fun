<?php

declare(strict_types=1);

namespace App\Actions\HelpCenter;

use App\Enums\HelpFeature;
use App\Models\HelpArticle;
use App\Models\HelpContextualLink;
use App\Models\User;
use App\Services\HelpCenter\HelpCacheInvalidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class SyncHelpArticleRelations
{
    public function __construct(private HelpCacheInvalidator $cache) {}

    public function handle(
        User $editor,
        HelpArticle $article,
        string $relatedCodes,
        string $contextualCodes,
        ?string $replacementCode,
    ): void {
        Gate::forUser($editor)->authorize('update', $article);
        $related = collect(preg_split('/[\s,]+/u', $relatedCodes) ?: [])
            ->filter()
            ->unique()
            ->take(20)
            ->values();
        $relatedArticles = HelpArticle::query()->whereIn('code', $related)->get(['id', 'code']);

        if ($relatedArticles->count() !== $related->count() || $relatedArticles->contains('id', $article->id)) {
            throw ValidationException::withMessages(['relatedCodes' => [__('help.errors.invalid_action')]]);
        }

        $contexts = collect(preg_split('/[\r\n]+/u', $contextualCodes) ?: [])
            ->map(function (string $value): ?array {
                [$feature, $context] = array_pad(explode(':', trim($value), 2), 2, null);

                return HelpFeature::tryFrom((string) $feature) !== null
                    && is_string($context)
                    && preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/D', $context) === 1
                        ? ['feature' => $feature, 'context' => $context]
                        : null;
            })->filter()->unique(fn (array $item): string => $item['feature'].':'.$item['context'])->take(30)->values();
        $rawContextCount = collect(preg_split('/[\r\n]+/u', $contextualCodes) ?: [])->filter(fn (string $value): bool => trim($value) !== '')->count();

        if ($contexts->count() !== $rawContextCount) {
            throw ValidationException::withMessages(['contextualCodes' => [__('help.errors.invalid_action')]]);
        }

        $replacement = null;

        if (is_string($replacementCode) && trim($replacementCode) !== '') {
            $replacement = HelpArticle::query()->where('code', trim($replacementCode))->first();

            if (! $replacement instanceof HelpArticle || $this->replacementCreatesCycle($article, $replacement)) {
                throw ValidationException::withMessages(['replacementCode' => [__('help.errors.invalid_action')]]);
            }
        }

        $currentRelated = DB::table('help_article_relations')
            ->where('help_article_id', $article->id)
            ->orderBy('position')
            ->orderBy('related_article_id')
            ->pluck('related_article_id')
            ->all();
        $requestedRelated = $related->map(fn (string $code): int => (int) $relatedArticles->firstWhere('code', $code)->id)->all();
        $currentContexts = HelpContextualLink::query()
            ->where('help_article_id', $article->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (HelpContextualLink $link): string => $link->feature_code->value.':'.$link->context_code)
            ->all();
        $requestedContexts = $contexts->map(fn (array $context): string => $context['feature'].':'.$context['context'])->all();

        if ($currentRelated === $requestedRelated
            && $currentContexts === $requestedContexts
            && $article->replacement_article_id === $replacement?->id) {
            return;
        }

        if ($article->status->isPublic()) {
            throw ValidationException::withMessages(['article' => [__('help.admin.validation.published_edit')]]);
        }

        DB::transaction(function () use ($article, $related, $relatedArticles, $contexts, $replacement, $editor): void {
            $locked = HelpArticle::query()->lockForUpdate()->findOrFail($article->id);
            DB::table('help_article_relations')->where('help_article_id', $locked->id)->delete();
            $byCode = $relatedArticles->keyBy('code');

            foreach ($related as $position => $code) {
                DB::table('help_article_relations')->insert([
                    'help_article_id' => $locked->id,
                    'related_article_id' => $byCode->get($code)->id,
                    'position' => $position,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            HelpContextualLink::query()->where('help_article_id', $locked->id)->delete();

            foreach ($contexts as $position => $context) {
                HelpContextualLink::query()->create([
                    'feature_code' => $context['feature'],
                    'context_code' => $context['context'],
                    'help_article_id' => $locked->id,
                    'position' => $position,
                    'is_active' => true,
                ]);
            }

            $locked->forceFill([
                'replacement_article_id' => $replacement?->id,
                'updated_by_id' => $editor->id,
                'content_version' => $locked->content_version + 1,
            ])->save();
        }, attempts: 3);
        $this->cache->changed($article->public_id);
    }

    private function replacementCreatesCycle(HelpArticle $article, HelpArticle $replacement): bool
    {
        $visited = [];
        $current = $replacement;

        while (true) {
            if ($current->id === $article->id || isset($visited[$current->id])) {
                return true;
            }

            $visited[$current->id] = true;

            if ($current->replacement_article_id === null) {
                return false;
            }

            $next = HelpArticle::query()->find($current->replacement_article_id);

            if (! $next instanceof HelpArticle) {
                return false;
            }

            $current = $next;
        }
    }
}
