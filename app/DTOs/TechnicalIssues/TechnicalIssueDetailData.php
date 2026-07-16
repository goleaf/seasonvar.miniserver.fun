<?php

declare(strict_types=1);

namespace App\DTOs\TechnicalIssues;

use App\Models\TechnicalIssueMessage;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class TechnicalIssueDetailData
{
    /**
     * @param  list<array{id: string, kind: string, internal: bool, author: string, body: string|null, created_at: string, attachments: array<int, array<string, int|string>>}>  $messages
     * @param  LengthAwarePaginator<int, TechnicalIssueMessage>|null  $messagePages
     * @param  list<array<string, mixed>>  $history
     * @param  list<array<string, mixed>>  $attachments
     * @param  array<string, mixed>|null  $diagnostics
     * @param  array<string, bool>  $permissions
     * @param  list<array{number: string, type: string, status: string, url: string}>  $relatedTickets
     */
    public function __construct(
        public TechnicalIssueCardData $card,
        public string $viewerMode,
        public ?string $expectedBehavior,
        public ?string $actualBehavior,
        public ?string $reproductionSteps,
        public ?string $playbackTimestamp,
        public ?string $audioLanguage,
        public ?string $subtitleLanguage,
        public ?string $qualityCode,
        public ?string $publicErrorCode,
        public ?string $resolutionType,
        public ?string $resolutionTypeLabel,
        public ?string $resolutionSummary,
        public ?string $mergedIntoNumber,
        public ?string $mergedIntoStatusLabel,
        public ?string $mergedIntoUrl,
        public array $messages,
        public ?LengthAwarePaginator $messagePages,
        public array $history,
        public array $attachments,
        public ?array $diagnostics,
        public array $permissions,
        public int $version,
        public ?int $licensedMediaId,
        public array $relatedTickets,
    ) {}
}
