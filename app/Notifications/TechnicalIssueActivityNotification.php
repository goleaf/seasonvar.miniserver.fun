<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\TechnicalIssueNotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class TechnicalIssueActivityNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly TechnicalIssueNotificationType $kind,
        private readonly string $issuePublicId,
        private readonly string $publicNumber,
        private readonly string $issueType,
        private readonly string $status,
        private readonly int $revision,
        private readonly ?string $canonicalPublicId = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'technical-issue.activity';
    }

    /** @return array{kind: string, issue_public_id: string, public_number: string, issue_type: string, status: string, revision: int, canonical_public_id: string|null} */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => $this->kind->value,
            'issue_public_id' => $this->issuePublicId,
            'public_number' => $this->publicNumber,
            'issue_type' => $this->issueType,
            'status' => $this->status,
            'revision' => $this->revision,
            'canonical_public_id' => $this->canonicalPublicId,
        ];
    }
}
