<?php

declare(strict_types=1);

namespace App\Services\HelpCenter;

use App\Models\HelpArticleFeedback;
use App\Models\HelpArticleReport;
use App\Models\User;

final class HelpAccountService
{
    public function __construct(private readonly HelpCenterSchema $schema) {}

    /** @return array<string, mixed> */
    public function export(User $user): array
    {
        if (! $this->schema->ready()) {
            return ['feedback' => [], 'outdated_reports' => []];
        }

        return [
            'feedback' => HelpArticleFeedback::query()
                ->where('user_id', $user->id)
                ->with(['article:id,public_id,code', 'translation:id,help_article_id,locale,title'])
                ->oldest('id')
                ->get()
                ->map(fn (HelpArticleFeedback $feedback): array => [
                    'article_public_id' => $feedback->article?->public_id,
                    'article_code' => $feedback->article?->code,
                    'locale' => $feedback->locale,
                    'value' => $feedback->value->value,
                    'reason' => $feedback->reason?->value,
                    'created_at' => $feedback->created_at?->toAtomString(),
                    'updated_at' => $feedback->updated_at?->toAtomString(),
                ])->all(),
            'outdated_reports' => HelpArticleReport::query()
                ->where('reporter_id', $user->id)
                ->with('article:id,public_id,code')
                ->oldest('id')
                ->get()
                ->map(fn (HelpArticleReport $report): array => [
                    'public_id' => $report->public_id,
                    'article_public_id' => $report->article?->public_id,
                    'article_code' => $report->article?->code,
                    'locale' => $report->locale,
                    'reason' => $report->reason->value,
                    'details' => $report->details,
                    'status' => $report->status,
                    'created_at' => $report->created_at?->toAtomString(),
                ])->all(),
        ];
    }

    public function prepareForDeletion(User $user): void
    {
        if (! $this->schema->ready()) {
            return;
        }

        HelpArticleFeedback::query()->where('user_id', $user->id)->update(['user_id' => null]);
        HelpArticleReport::query()->where('reporter_id', $user->id)->update(['reporter_id' => null]);
    }
}
