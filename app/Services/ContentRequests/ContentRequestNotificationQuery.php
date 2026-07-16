<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use App\DTOs\ContentRequests\ContentRequestNotificationData;
use App\Enums\ContentRequestNotificationType;
use App\Enums\ContentRequestStatus;
use App\Models\ContentRequest;
use App\Models\User;
use App\Services\Auth\AccountDateTimeFormatter;
use App\Services\Auth\AccountSettingsService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;

final readonly class ContentRequestNotificationQuery
{
    public function __construct(private AccountSettingsService $settings, private AccountDateTimeFormatter $dateTimes) {}

    /** @return LengthAwarePaginator<int, ContentRequestNotificationData> */
    public function forUser(User $user): LengthAwarePaginator
    {
        $paginator = $user->notifications()->where('type', 'content-request.activity')
            ->latest('created_at')->latest('id')->paginate(10, pageName: 'requestNotificationPage')->withQueryString();
        $publicIds = $paginator->getCollection()->pluck('data.request_public_id')->filter(is_string(...))->unique()->values();
        $requests = ContentRequest::query()->whereIn('public_id', $publicIds)->get(['id', 'public_id', 'requester_id', 'is_public', 'status', 'merged_into_id'])->keyBy('public_id');
        $settings = $this->settings->resolve($user);

        return $paginator->through(function (DatabaseNotification $notification) use ($requests, $settings, $user): ContentRequestNotificationData {
            $data = $notification->data;
            $kind = is_string($data['kind'] ?? null) ? ContentRequestNotificationType::tryFrom($data['kind']) : null;
            $status = is_string($data['status'] ?? null) ? ContentRequestStatus::tryFrom($data['status']) : null;
            $request = is_string($data['request_public_id'] ?? null) ? $requests->get($data['request_public_id']) : null;
            $canonicalPublicId = is_string($data['canonical_public_id'] ?? null) ? $data['canonical_public_id'] : null;
            $url = null;

            if ($canonicalPublicId !== null) {
                $url = route('requests.show', ['contentRequest' => $canonicalPublicId]);
            } elseif ($request instanceof ContentRequest && ($request->is_public || $request->requester_id === $user->id)) {
                $url = route('requests.show', $request);
            }

            return new ContentRequestNotificationData(
                id: (string) $notification->id,
                isRead: $notification->read_at !== null,
                label: $kind !== null ? __('requests.notifications.'.$kind->value) : __('requests.notifications.activity'),
                detail: $status !== null ? __('requests.notifications.status', ['status' => $status->label()]) : null,
                url: $url,
                createdAtIso: $notification->created_at?->toAtomString() ?? '',
                createdAtLabel: $notification->created_at !== null ? $this->dateTimes->value($notification->created_at, $settings->locale, $settings->timezone) : '',
            );
        });
    }
}
