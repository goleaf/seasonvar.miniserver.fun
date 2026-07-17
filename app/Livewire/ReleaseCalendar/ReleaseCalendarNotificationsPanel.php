<?php

declare(strict_types=1);

namespace App\Livewire\ReleaseCalendar;

use App\Actions\ReleaseCalendar\MarkReleaseCalendarNotificationRead;
use App\Models\User;
use App\Services\ReleaseCalendar\ReleaseCalendarNotificationQuery;
use App\Services\ReleaseCalendar\ReleaseCalendarSchema;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class ReleaseCalendarNotificationsPanel extends Component
{
    use WithPagination;

    public string $notice = '';

    public bool $queryFailed = false;

    public function markRead(string $notificationId, MarkReleaseCalendarNotificationRead $notifications): void
    {
        $notifications->one($this->user(), $notificationId);
    }

    public function markAllRead(MarkReleaseCalendarNotificationRead $notifications): void
    {
        $notifications->all($this->user());
        $this->notice = __('calendar.notifications.marked_all_read');
    }

    public function render(ReleaseCalendarSchema $schema, ReleaseCalendarNotificationQuery $query): View
    {
        $notifications = null;
        $this->queryFailed = false;

        if ($schema->ready()) {
            try {
                $notifications = $query->forUser($this->user());
            } catch (Throwable $exception) {
                report($exception);
                $this->queryFailed = true;
            }
        }

        return view('livewire.release-calendar.release-calendar-notifications-panel', [
            'schemaReady' => $schema->ready(),
            'notifications' => $notifications,
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
