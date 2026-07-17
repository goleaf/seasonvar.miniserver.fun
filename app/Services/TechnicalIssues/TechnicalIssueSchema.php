<?php

declare(strict_types=1);

namespace App\Services\TechnicalIssues;

use Illuminate\Support\Facades\Schema;
use Throwable;

final class TechnicalIssueSchema
{
    private ?bool $ready = null;

    public function ready(): bool
    {
        if ($this->ready !== null) {
            return $this->ready;
        }

        $tables = [
            'technical_issues' => ['id', 'public_id', 'public_number', 'requester_id', 'assigned_to_id', 'type', 'status', 'severity', 'priority', 'severity_sort_rank', 'priority_sort_rank', 'target_type', 'target_label_snapshot', 'exact_identity_hash', 'active_identity_key', 'submission_key', 'version'],
            'technical_issue_diagnostics' => ['technical_issue_id', 'browser_family', 'operating_system', 'device_category', 'network_online'],
            'technical_issue_messages' => ['id', 'public_id', 'technical_issue_id', 'author_id', 'visibility', 'body', 'submission_key'],
            'technical_issue_attachments' => ['id', 'public_id', 'technical_issue_id', 'uploader_id', 'disk', 'path', 'mime_type', 'content_hash'],
            'technical_issue_status_histories' => ['technical_issue_id', 'actor_id', 'from_status', 'to_status', 'private_note', 'idempotency_key'],
            'technical_issue_assignments' => ['technical_issue_id', 'assignee_id', 'support_team', 'ended_at'],
            'technical_issue_confirmations' => ['technical_issue_id', 'user_id', 'verification_state'],
            'technical_issue_followers' => ['technical_issue_id', 'user_id'],
            'technical_issue_occurrences' => ['technical_issue_id', 'user_id', 'browser_family', 'device_category', 'playback_position_seconds', 'diagnostics_pruned_at'],
            'technical_issue_merges' => ['duplicate_issue_id', 'canonical_issue_id', 'merged_by_id'],
            'technical_issue_redactions' => ['technical_issue_id', 'field', 'reason_code', 'before_hash', 'after_hash'],
            'technical_issue_source_actions' => ['technical_issue_id', 'licensed_media_id', 'actor_id', 'action'],
            'technical_issue_notification_preferences' => ['user_id', 'requester_updates', 'confirmer_updates', 'follower_updates', 'support_replies'],
            'notifications' => ['id', 'type', 'notifiable_type', 'notifiable_id', 'data', 'read_at'],
        ];

        try {
            return $this->ready = collect($tables)->every(
                fn (array $columns, string $table): bool => Schema::hasTable($table) && Schema::hasColumns($table, $columns),
            );
        } catch (Throwable) {
            return $this->ready = false;
        }
    }
}
