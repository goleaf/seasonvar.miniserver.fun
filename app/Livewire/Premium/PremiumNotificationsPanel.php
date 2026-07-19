<?php

declare(strict_types=1);

namespace App\Livewire\Premium;

use App\Actions\Premium\MarkPremiumNotificationRead;
use App\Livewire\Concerns\InteractsWithPaginationIslands;
use App\Models\User;
use App\Services\Premium\PremiumNotificationQuery;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class PremiumNotificationsPanel extends Component
{
    use InteractsWithPaginationIslands;
    use WithPagination;

    public string $notice = '';

    public bool $queryFailed = false;

    public function markRead(string $notificationId, MarkPremiumNotificationRead $notifications): void
    {
        $notifications->one($this->user(), $notificationId);
    }

    public function markAllRead(MarkPremiumNotificationRead $notifications): void
    {
        $notifications->all($this->user());
        $this->notice = __('premium.notifications.marked_all_read');
    }

    public function render(PremiumNotificationQuery $query): View
    {
        $notifications = null;
        $this->queryFailed = false;

        try {
            $notifications = $query->forUser($this->user());
        } catch (Throwable $exception) {
            report($exception);
            $this->queryFailed = true;
        }

        return view('livewire.premium.notifications-panel', ['notifications' => $notifications]);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
