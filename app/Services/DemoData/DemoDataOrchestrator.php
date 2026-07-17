<?php

declare(strict_types=1);

namespace App\Services\DemoData;

use App\Contracts\DemoDataStage;
use App\DTOs\DemoData\DemoAuditReport;
use App\DTOs\DemoData\DemoDataOptions;
use App\Enums\ReviewOrigin;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleReview;
use App\Services\DemoData\Stages\DemoAccountStage;
use App\Services\DemoData\Stages\DemoCatalogActivityStage;
use App\Services\DemoData\Stages\DemoCommunityStage;
use App\Services\DemoData\Stages\DemoContentRequestStage;
use App\Services\DemoData\Stages\DemoModerationStage;
use App\Services\DemoData\Stages\DemoNotificationSyncStage;
use App\Services\DemoData\Stages\DemoOrganizationStage;
use App\Services\DemoData\Stages\DemoTechnicalIssueStage;
use Closure;
use Illuminate\Support\Facades\Schema;
use LogicException;

final readonly class DemoDataOrchestrator
{
    public function __construct(
        private DemoAccountStage $accounts,
        private DemoOrganizationStage $organization,
        private DemoCatalogActivityStage $catalogActivity,
        private DemoCommunityStage $community,
        private DemoContentRequestStage $contentRequests,
        private DemoModerationStage $moderation,
        private DemoTechnicalIssueStage $technicalIssues,
        private DemoNotificationSyncStage $notificationsSync,
        private DemoDataAuditor $auditor,
    ) {}

    public function run(?Closure $progress = null): DemoAuditReport
    {
        $options = DemoDataOptions::fromConfig();
        $options->assertEnvironment(app()->environment());
        $this->assertSchema();
        $publishedTitleCount = CatalogTitle::query()->published()->count();

        if ($publishedTitleCount < 1) {
            throw new LogicException('Для демонстрационного наполнения нужен хотя бы один опубликованный тайтл.');
        }

        $this->assertCapacity($options, $publishedTitleCount);
        $baseline = [
            'catalog_titles' => CatalogTitle::query()->count(),
            'provider_reviews' => CatalogTitleReview::query()
                ->where('origin', ReviewOrigin::Provider->value)
                ->count(),
        ];

        foreach ($this->stages() as $stage) {
            $stage->run($options, $progress);
        }

        $report = $this->auditor->audit($options, $baseline);
        $this->auditor->assertValid($report);

        return $report;
    }

    /** @return list<DemoDataStage> */
    private function stages(): array
    {
        return [
            $this->accounts,
            $this->organization,
            $this->catalogActivity,
            $this->community,
            $this->contentRequests,
            $this->moderation,
            $this->technicalIssues,
            $this->notificationsSync,
        ];
    }

    private function assertCapacity(DemoDataOptions $options, int $publishedTitleCount): void
    {
        $selectedPairs = $options->userCount * $options->selectedTitleCount($publishedTitleCount);
        $estimatedBytes = $selectedPairs * 12_000;
        $requiredBytes = $options->minimumFreeBytes + $estimatedBytes;
        $freeBytes = disk_free_space(database_path());

        if ($freeBytes === false) {
            throw new LogicException('Не удалось определить свободное место перед демонстрационным наполнением.');
        }

        if ($freeBytes < $requiredBytes) {
            throw new LogicException(sprintf(
                'Недостаточно места для демонстрационных данных: требуется не менее %d байт, доступно %d байт.',
                $requiredBytes,
                $freeBytes,
            ));
        }
    }

    private function assertSchema(): void
    {
        /** @var array<string, list<string>> $required */
        $required = [
            'users' => ['id', 'email', 'password', 'email_verified_at'],
            'user_profiles' => ['user_id', 'username', 'avatar_path', 'cover_path'],
            'user_account_settings' => ['user_id'],
            'catalog_titles' => ['id', 'is_published', 'publication_status'],
            'seasons' => ['id', 'catalog_title_id'],
            'episodes' => ['id', 'season_id'],
            'licensed_media' => ['id', 'catalog_title_id'],
            'user_tags' => ['id', 'user_id'],
            'tags' => ['id'],
            'tag_translations' => ['tag_id'],
            'catalog_title_user_tag' => ['catalog_title_id', 'user_tag_id'],
            'catalog_title_tag' => ['catalog_title_id', 'tag_id'],
            'catalog_collections' => ['id', 'owner_id'],
            'catalog_collection_items' => ['catalog_collection_id', 'catalog_title_id'],
            'catalog_title_user_states' => ['user_id', 'catalog_title_id'],
            'episode_view_progress' => ['user_id', 'episode_id'],
            'catalog_title_reviews' => ['id', 'catalog_title_id', 'user_id', 'origin'],
            'catalog_title_review_votes' => ['catalog_title_review_id', 'user_id'],
            'comments' => ['id', 'user_id', 'parent_id', 'target_type'],
            'comment_reactions' => ['comment_id', 'user_id'],
            'content_requests' => ['id', 'requester_id'],
            'content_request_votes' => ['content_request_id', 'user_id'],
            'content_request_followers' => ['content_request_id', 'user_id'],
            'content_request_status_histories' => ['content_request_id'],
            'content_request_source_links' => ['content_request_id'],
            'content_request_external_identifiers' => ['content_request_id'],
            'content_request_clarifications' => ['content_request_id'],
            'comment_reports' => ['comment_id', 'reporter_id'],
            'catalog_title_review_reports' => ['catalog_title_review_id', 'reporter_id'],
            'catalog_collection_reports' => ['catalog_collection_id', 'reporter_id'],
            'user_profile_reports' => ['target_user_id', 'reporter_id'],
            'user_blocks' => ['blocker_id', 'blocked_id'],
            'user_mutes' => ['muter_id', 'muted_id'],
            'comment_restrictions' => ['user_id'],
            'catalog_title_review_restrictions' => ['user_id'],
            'technical_issues' => ['id', 'requester_id'],
            'technical_issue_diagnostics' => ['technical_issue_id'],
            'technical_issue_messages' => ['technical_issue_id'],
            'technical_issue_attachments' => ['technical_issue_id', 'path'],
            'technical_issue_status_histories' => ['technical_issue_id'],
            'technical_issue_assignments' => ['technical_issue_id'],
            'technical_issue_confirmations' => ['technical_issue_id', 'user_id'],
            'technical_issue_followers' => ['technical_issue_id', 'user_id'],
            'technical_issue_occurrences' => ['technical_issue_id', 'user_id'],
            'technical_issue_merges' => ['duplicate_issue_id', 'canonical_issue_id'],
            'technical_issue_redactions' => ['technical_issue_id'],
            'technical_issue_source_actions' => ['technical_issue_id'],
            'notifications' => ['id', 'notifiable_id', 'data'],
            'api_sync_mutations' => ['user_id', 'mutation_id', 'status'],
            'api_sync_changes' => ['user_id'],
        ];

        foreach ($required as $table => $columns) {
            if (! Schema::hasTable($table)) {
                throw new LogicException("Для демонстрационного наполнения отсутствует таблица {$table}.");
            }

            if (! Schema::hasColumns($table, $columns)) {
                $missing = array_values(array_filter(
                    $columns,
                    static fn (string $column): bool => ! Schema::hasColumn($table, $column),
                ));
                throw new LogicException(sprintf(
                    'В таблице %s отсутствуют обязательные колонки: %s.',
                    $table,
                    implode(', ', $missing),
                ));
            }
        }
    }
}
