<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\DTOs\Premium\PremiumNotificationData;
use App\Enums\PremiumNotificationType;
use App\Models\User;
use App\Services\Auth\AccountDateTimeFormatter;
use App\Services\Auth\AccountSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;
use Throwable;

final readonly class PremiumNotificationQuery
{
    public function __construct(
        private AccountSettingsService $settings,
        private AccountDateTimeFormatter $dateTimes,
    ) {}

    /** @return LengthAwarePaginator<int, PremiumNotificationData> */
    public function forUser(User $user): LengthAwarePaginator
    {
        $settings = $this->settings->resolve($user);

        return $user->notifications()
            ->where('type', 'premium.activity')
            ->latest('created_at')
            ->latest('id')
            ->paginate(10, pageName: 'premiumNotificationPage')
            ->withQueryString()
            ->through(function (DatabaseNotification $notification) use ($settings): PremiumNotificationData {
                $data = $notification->data;
                $kind = is_string($data['kind'] ?? null) ? PremiumNotificationType::tryFrom($data['kind']) : null;
                $lifetime = ($data['lifetime'] ?? false) === true;
                $expiresAt = $this->expiration($data['expires_at'] ?? null);
                $detail = $lifetime
                    ? __('premium.notifications.lifetime_detail')
                    : ($expiresAt instanceof CarbonImmutable
                        ? __('premium.notifications.expires_detail', [
                            'date' => $this->dateTimes->value($expiresAt, $settings->locale, $settings->timezone),
                        ])
                        : null);

                return new PremiumNotificationData(
                    id: (string) $notification->id,
                    isRead: $notification->read_at !== null,
                    label: $kind?->label() ?? __('premium.title'),
                    detail: $detail,
                    url: route('settings.index', ['section' => 'premium']),
                    createdAtIso: $notification->created_at?->toAtomString() ?? '',
                    createdAtLabel: $notification->created_at !== null
                        ? $this->dateTimes->value($notification->created_at, $settings->locale, $settings->timezone)
                        : '',
                );
            });
    }

    private function expiration(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
