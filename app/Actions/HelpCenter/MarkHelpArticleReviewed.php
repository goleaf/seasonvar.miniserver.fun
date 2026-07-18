<?php

declare(strict_types=1);

namespace App\Actions\HelpCenter;

use App\Models\HelpArticle;
use App\Models\User;
use App\Services\HelpCenter\HelpCacheInvalidator;
use Illuminate\Support\Facades\Gate;

final readonly class MarkHelpArticleReviewed
{
    public function __construct(private HelpCacheInvalidator $cache) {}

    public function handle(User $editor, HelpArticle $article): HelpArticle
    {
        Gate::forUser($editor)->authorize('update', $article);
        $article->forceFill([
            'last_reviewed_at' => now(),
            'review_due_at' => now()->addDays(max(1, (int) config('help-center.review_cycle_days', 180))),
            'updated_by_id' => $editor->id,
        ])->save();
        $this->cache->changed($article->public_id);

        return $article->fresh();
    }
}
