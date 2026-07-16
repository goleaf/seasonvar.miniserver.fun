<?php

declare(strict_types=1);

namespace App\Services\TechnicalIssues;

use App\DTOs\TechnicalIssues\TechnicalIssueCardData;
use App\DTOs\TechnicalIssues\TechnicalIssueDetailData;
use App\Enums\TechnicalIssueMessageVisibility;
use App\Models\TechnicalIssue;
use App\Models\TechnicalIssueAttachment;
use App\Models\TechnicalIssueDiagnostic;
use App\Models\TechnicalIssueMessage;
use App\Models\TechnicalIssueStatusHistory;
use App\Models\User;
use App\Services\Auth\AccountDateTimeFormatter;
use App\Services\Auth\AccountSettingsService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;

final readonly class TechnicalIssuePresenter
{
    public function __construct(
        private AccountSettingsService $settings,
        private AccountDateTimeFormatter $dateTimes,
        private TechnicalIssueTextSanitizer $text,
    ) {}

    public function card(TechnicalIssue $issue, User $viewer, bool $staff = false): TechnicalIssueCardData
    {
        $settings = $this->settings->resolve($viewer);
        $participant = ! $staff && $issue->requester_id !== $viewer->id;

        return new TechnicalIssueCardData(
            id: $issue->id,
            publicId: $issue->public_id,
            number: $issue->public_number,
            type: $issue->type->value,
            typeLabel: $issue->type->label(),
            status: $issue->status->value,
            statusLabel: $issue->status->label(),
            severity: $issue->severity->value,
            severityLabel: $issue->severity->label(),
            priority: $issue->priority->value,
            priorityLabel: $issue->priority->label(),
            targetLabel: $this->targetLabel($issue),
            summary: $participant ? null : $this->text->display($issue->summary),
            createdAt: $this->dateTimes->value($issue->created_at, $settings->locale, $settings->timezone),
            updatedAt: $this->dateTimes->value($issue->updated_at, $settings->locale, $settings->timezone),
            attachmentCount: $participant ? 0 : (int) ($issue->attachments_count ?? 0),
            messageCount: $participant ? 0 : (int) ($issue->messages_count ?? 0),
            confirmationCount: (int) ($issue->confirmations_count ?? 0),
            affectedUserCount: max(
                (int) ($issue->occurrences_count ?? 0),
                (int) ($issue->confirmations_count ?? 0) + ($issue->requester_id !== null ? 1 : 0),
            ),
            isFollowing: (bool) ($issue->viewer_is_following ?? false),
            hasConfirmed: (bool) ($issue->viewer_has_confirmed ?? false),
            needsRequesterResponse: in_array($issue->status->value, ['clarification_needed', 'waiting_for_requester'], true),
            isAssigned: $staff && $issue->assigned_to_id !== null,
            requesterName: $staff ? $issue->requester?->name : null,
            sourceHealth: $staff ? $issue->licensedMedia?->health_status?->value : null,
            url: $this->issueUrl($issue),
        );
    }

    /**
     * @param  list<array{number: string, type: string, status: string, url: string}>  $relatedTickets
     * @param  LengthAwarePaginator<int, TechnicalIssueMessage>|null  $messagePages
     */
    public function detail(
        TechnicalIssue $issue,
        User $viewer,
        bool $staff,
        array $relatedTickets = [],
        ?LengthAwarePaginator $messagePages = null,
    ): TechnicalIssueDetailData {
        $requester = $issue->requester_id === $viewer->id;
        $viewerMode = $staff ? 'staff' : ($requester ? 'requester' : 'participant');
        $settings = $this->settings->resolve($viewer);

        if ($viewerMode === 'participant') {
            return new TechnicalIssueDetailData(
                card: $this->card($issue, $viewer),
                viewerMode: $viewerMode,
                expectedBehavior: null,
                actualBehavior: null,
                reproductionSteps: null,
                playbackTimestamp: null,
                audioLanguage: null,
                subtitleLanguage: null,
                qualityCode: null,
                publicErrorCode: $issue->public_error_code,
                resolutionType: $issue->resolution_type?->value,
                resolutionTypeLabel: $issue->resolution_type?->label(),
                resolutionSummary: $this->text->display($issue->resolution_summary),
                mergedIntoNumber: $issue->mergedInto?->public_number,
                mergedIntoStatusLabel: $issue->mergedInto?->status?->label(),
                mergedIntoUrl: $issue->mergedInto instanceof TechnicalIssue ? $this->issueUrl($issue->mergedInto) : null,
                messages: [],
                messagePages: null,
                history: [],
                attachments: [],
                diagnostics: null,
                permissions: $this->permissions($viewer, $issue, $staff),
                version: $issue->version,
                licensedMediaId: null,
                relatedTickets: [],
            );
        }

        $messagePresenter = function (TechnicalIssueMessage $message) use ($staff, $issue, $settings): array {
            $isRequester = $message->author_id !== null && $message->author_id === $issue->requester_id;

            return [
                'id' => $message->public_id,
                'kind' => $message->kind,
                'internal' => $message->visibility === TechnicalIssueMessageVisibility::Internal,
                'author' => $isRequester
                    ? (string) __('issues.messages.requester')
                    : (! $staff
                        ? (string) __('issues.messages.support')
                        : ($message->author_id === null
                            ? (string) __('issues.messages.deleted_user')
                            : $message->author->name)),
                'body' => $this->text->display($message->body),
                'created_at' => $message->created_at !== null ? $this->dateTimes->value($message->created_at, $settings->locale, $settings->timezone) : '',
                'attachments' => $message->attachments
                    ->values()
                    ->map(fn (TechnicalIssueAttachment $attachment, int $index): array => $this->attachment($issue, $attachment, $index + 1))
                    ->all(),
            ];
        };

        $messages = $issue->messages->map($messagePresenter)->all();
        $history = $issue->statusHistory->map(fn (TechnicalIssueStatusHistory $history): array => [
            'id' => $history->id,
            'from' => $history->from_status?->value,
            'to' => $history->to_status->value,
            'label' => $history->to_status->label(),
            'reason' => $this->text->display($history->public_message),
            'private_note' => $staff ? $this->text->display($history->private_note) : null,
            'created_at' => $this->dateTimes->value($history->created_at, $settings->locale, $settings->timezone),
        ])->all();
        $attachments = $issue->attachments
            ->whereNull('technical_issue_message_id')
            ->values()
            ->map(fn (TechnicalIssueAttachment $attachment, int $index): array => $this->attachment($issue, $attachment, $index + 1))
            ->all();
        $diagnosticRelation = $issue->getRelation('diagnostic');
        $diagnostic = $diagnosticRelation instanceof TechnicalIssueDiagnostic ? $diagnosticRelation : null;
        $diagnostics = array_filter([
            'browser' => $diagnostic?->browser_family,
            'browser_major' => $diagnostic?->browser_major,
            'operating_system' => $diagnostic?->operating_system,
            'device' => $diagnostic?->device_category,
            'viewport' => $diagnostic?->viewport_width !== null && $diagnostic->viewport_height !== null
                ? $diagnostic->viewport_width.' × '.$diagnostic->viewport_height
                : null,
            'timezone' => $diagnostic?->timezone,
            'network_online' => $diagnostic?->network_online,
            'player_component' => $diagnostic?->player_component,
            'source_health' => $staff ? $diagnostic?->source_health_code : null,
            'affected_users' => $staff ? max(
                (int) ($issue->occurrences_count ?? 0),
                (int) ($issue->confirmations_count ?? 0) + ($issue->requester_id !== null ? 1 : 0),
            ) : null,
            'browser_distribution' => $staff ? $issue->getAttribute('occurrence_browser_distribution') : null,
            'device_distribution' => $staff ? $issue->getAttribute('occurrence_device_distribution') : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
        $diagnostics = $diagnostics === [] ? null : $diagnostics;

        return new TechnicalIssueDetailData(
            card: $this->card($issue, $viewer, $staff),
            viewerMode: $viewerMode,
            expectedBehavior: $this->text->display($issue->expected_behavior),
            actualBehavior: $this->text->display($issue->actual_behavior),
            reproductionSteps: $this->text->display($issue->reproduction_steps),
            playbackTimestamp: $issue->playback_position_seconds !== null ? $this->timestamp($issue->playback_position_seconds) : null,
            audioLanguage: $issue->audio_language,
            subtitleLanguage: $issue->subtitle_language,
            qualityCode: $issue->quality_code,
            publicErrorCode: $issue->public_error_code,
            resolutionType: $issue->resolution_type?->value,
            resolutionTypeLabel: $issue->resolution_type?->label(),
            resolutionSummary: $this->text->display($issue->resolution_summary),
            mergedIntoNumber: $issue->mergedInto?->public_number,
            mergedIntoStatusLabel: $issue->mergedInto?->status?->label(),
            mergedIntoUrl: $issue->mergedInto instanceof TechnicalIssue ? $this->issueUrl($issue->mergedInto) : null,
            messages: $messages,
            messagePages: $messagePages,
            history: $history,
            attachments: $attachments,
            diagnostics: $diagnostics,
            permissions: $this->permissions($viewer, $issue, $staff),
            version: $issue->version,
            licensedMediaId: $staff ? $issue->licensed_media_id : null,
            relatedTickets: $staff ? $relatedTickets : [],
        );
    }

    private function targetLabel(TechnicalIssue $issue): string
    {
        $label = $issue->catalogTitle?->title;

        if ($label === null && $issue->target_label_snapshot !== '') {
            return $issue->target_label_snapshot;
        }

        if ($label === null) {
            return $issue->feature_code !== null
                ? __("issues.features.{$issue->feature_code}")
                : $issue->target_type->label();
        }

        if ($issue->season !== null) {
            $label .= ' · '.__('issues.target_summary.season', ['number' => $issue->season->number]);
        }

        if ($issue->episode !== null) {
            $label .= ' · '.__('issues.target_summary.episode', ['number' => $issue->episode->number]);
        }

        return $label;
    }

    /** @return array<string, bool> */
    private function permissions(User $viewer, TechnicalIssue $issue, bool $staff): array
    {
        return [
            'edit' => Gate::forUser($viewer)->allows('update', $issue),
            'withdraw' => Gate::forUser($viewer)->allows('withdraw', $issue),
            'reply' => Gate::forUser($viewer)->allows('reply', $issue),
            'confirm' => Gate::forUser($viewer)->allows('confirm', $issue),
            'follow' => Gate::forUser($viewer)->allows('follow', $issue),
            'verify' => Gate::forUser($viewer)->allows('verify', $issue),
            'reopen' => Gate::forUser($viewer)->allows('reopen', $issue),
            'manage' => $staff,
        ];
    }

    /** @return array<string, int|string> */
    private function attachment(
        TechnicalIssue $issue,
        TechnicalIssueAttachment $attachment,
        int $position,
    ): array {
        return [
            'id' => $attachment->public_id,
            'name' => __('issues.attachments.screenshot_name', ['number' => $position]).'.'.$attachment->extension,
            'mime' => $attachment->mime_type,
            'size' => $attachment->size_bytes,
            'width' => $attachment->width,
            'height' => $attachment->height,
            'url' => route('issues.attachments.show', ['technicalIssue' => $issue, 'attachment' => $attachment]),
        ];
    }

    public function issueUrl(TechnicalIssue $issue): string
    {
        $locale = App::getLocale();

        return in_array($locale, config('technical-issues.supported_locales', []), true)
            ? route('localized.issues.show', ['locale' => $locale, 'technicalIssue' => $issue])
            : route('issues.show', $issue);
    }

    private function timestamp(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remaining = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $remaining)
            : sprintf('%d:%02d', $minutes, $remaining);
    }
}
