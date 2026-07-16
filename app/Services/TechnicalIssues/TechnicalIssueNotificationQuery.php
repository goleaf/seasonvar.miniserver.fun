<?php

declare(strict_types=1);

namespace App\Services\TechnicalIssues;

use App\DTOs\TechnicalIssues\TechnicalIssueNotificationData;
use App\Enums\TechnicalIssueNotificationType;
use App\Enums\TechnicalIssueStatus;
use App\Enums\TechnicalIssueType;
use App\Models\TechnicalIssue;
use App\Models\User;
use App\Services\Auth\AccountDateTimeFormatter;
use App\Services\Auth\AccountSettingsService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;

final readonly class TechnicalIssueNotificationQuery
{
    public function __construct(private AccountSettingsService $settings, private AccountDateTimeFormatter $dateTimes) {}

    /** @return LengthAwarePaginator<int, TechnicalIssueNotificationData> */
    public function forUser(User $user): LengthAwarePaginator
    {
        $paginator = $user->notifications()
            ->where('type', 'technical-issue.activity')
            ->latest('created_at')
            ->latest('id')
            ->paginate(10, pageName: 'issueNotificationPage')
            ->withQueryString();
        $publicIds = $paginator->getCollection()
            ->pluck('data.issue_public_id')
            ->filter(static fn (mixed $value): bool => is_string($value))
            ->unique()
            ->values();
        $issues = TechnicalIssue::query()
            ->whereIn('public_id', $publicIds)
            ->when(! Gate::forUser($user)->allows('manage-technical-issues'), fn ($query) => $query
                ->where(fn ($query) => $query
                    ->where('requester_id', $user->id)
                    ->orWhereHas('followers', fn ($query) => $query->where('user_id', $user->id))
                    ->orWhereHas('confirmations', fn ($query) => $query->where('user_id', $user->id))))
            ->get(['id', 'public_id', 'status'])
            ->keyBy('public_id');
        $settings = $this->settings->resolve($user);

        return $paginator->through(function (DatabaseNotification $notification) use ($issues, $settings): TechnicalIssueNotificationData {
            $data = $notification->data;
            $kind = is_string($data['kind'] ?? null) ? TechnicalIssueNotificationType::tryFrom($data['kind']) : null;
            $status = is_string($data['status'] ?? null) ? TechnicalIssueStatus::tryFrom($data['status']) : null;
            $type = is_string($data['issue_type'] ?? null) ? TechnicalIssueType::tryFrom($data['issue_type']) : null;
            $number = is_string($data['public_number'] ?? null) && preg_match('/^ISS-[A-F0-9]{20}$/D', $data['public_number']) === 1
                ? $data['public_number']
                : null;
            $issue = is_string($data['issue_public_id'] ?? null) ? $issues->get($data['issue_public_id']) : null;
            $canonical = is_string($data['canonical_public_id'] ?? null) ? $data['canonical_public_id'] : null;
            $publicId = $canonical ?? $issue?->public_id;

            return new TechnicalIssueNotificationData(
                id: (string) $notification->id,
                isRead: $notification->read_at !== null,
                label: $kind !== null ? __("issues.notifications.{$kind->value}") : __('issues.notifications.activity'),
                detail: $number !== null && $type !== null && $status !== null
                    ? __('issues.notifications.ticket_status', ['number' => $number, 'type' => $type->label(), 'status' => $status->label()])
                    : ($status !== null ? __('issues.notifications.status', ['status' => $status->label()]) : null),
                url: $publicId !== null ? $this->issueUrl($publicId) : null,
                createdAtIso: $notification->created_at?->toAtomString() ?? '',
                createdAtLabel: $notification->created_at !== null
                    ? $this->dateTimes->value($notification->created_at, $settings->locale, $settings->timezone)
                    : '',
            );
        });
    }

    private function issueUrl(string $publicId): string
    {
        return in_array(App::getLocale(), config('technical-issues.supported_locales', []), true)
            ? route('localized.issues.show', ['locale' => App::getLocale(), 'technicalIssue' => $publicId])
            : route('issues.show', ['technicalIssue' => $publicId]);
    }
}
