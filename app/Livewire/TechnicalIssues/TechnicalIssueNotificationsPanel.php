<?php

declare(strict_types=1);

namespace App\Livewire\TechnicalIssues;

use App\Actions\TechnicalIssues\MarkTechnicalIssueNotificationRead;
use App\Actions\TechnicalIssues\UpdateTechnicalIssueNotificationPreferences;
use App\Exceptions\TechnicalIssues\TechnicalIssueActionException;
use App\Models\TechnicalIssueNotificationPreference;
use App\Models\User;
use App\Services\TechnicalIssues\TechnicalIssueNotificationQuery;
use App\Services\TechnicalIssues\TechnicalIssueSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class TechnicalIssueNotificationsPanel extends Component
{
    use WithPagination;

    #[Locked]
    public string $issueLocale = 'ru';

    public bool $requesterUpdates = true;

    public bool $confirmerUpdates = true;

    public bool $followerUpdates = true;

    public bool $supportReplies = true;

    public ?string $statusMessage = null;

    public ?string $actionError = null;

    public function mount(TechnicalIssueSchema $schema): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $this->issueLocale = app()->getLocale();

        if (! $schema->ready()) {
            return;
        }

        $preference = TechnicalIssueNotificationPreference::query()->find($user->id);

        if ($preference instanceof TechnicalIssueNotificationPreference) {
            $this->requesterUpdates = $preference->requester_updates;
            $this->confirmerUpdates = $preference->confirmer_updates;
            $this->followerUpdates = $preference->follower_updates;
            $this->supportReplies = $preference->support_replies;
        }
    }

    public function hydrate(): void
    {
        if (in_array($this->issueLocale, config('technical-issues.supported_locales', []), true)) {
            app()->setLocale($this->issueLocale);
        }
    }

    public function savePreferences(UpdateTechnicalIssueNotificationPreferences $action): void
    {
        $this->perform(fn (User $user) => $action->handle($user, [
            'requester_updates' => $this->requesterUpdates,
            'confirmer_updates' => $this->confirmerUpdates,
            'follower_updates' => $this->followerUpdates,
            'support_replies' => $this->supportReplies,
        ]), __('issues.states.saved'));
    }

    public function markRead(string $notificationId, MarkTechnicalIssueNotificationRead $action): void
    {
        $this->perform(fn (User $user) => $action->one($user, $notificationId), __('issues.notifications.marked_read'));
    }

    public function markAllRead(MarkTechnicalIssueNotificationRead $action): void
    {
        $this->perform(fn (User $user) => $action->all($user), __('issues.notifications.marked_all_read'));
    }

    public function render(TechnicalIssueNotificationQuery $query, TechnicalIssueSchema $schema): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $notifications = $this->emptyPaginator();

        if ($schema->ready()) {
            try {
                $notifications = $query->forUser($user);
            } catch (Throwable $exception) {
                report($exception);
                $this->actionError = __('issues.errors.query_failed');
            }
        }

        return view('livewire.technical-issues.notifications-panel', [
            'schemaReady' => $schema->ready(),
            'notifications' => $notifications,
        ]);
    }

    private function perform(callable $operation, string $success): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            $operation($user);
            $this->statusMessage = $success;
            $this->actionError = null;
        } catch (TechnicalIssueActionException $exception) {
            $this->statusMessage = null;
            $this->actionError = __($exception->translationKey, $exception->replace);
        } catch (Throwable $exception) {
            report($exception);
            $this->statusMessage = null;
            $this->actionError = __('issues.errors.action_failed');
        }
    }

    /** @return LengthAwarePaginator<int, mixed> */
    private function emptyPaginator(): LengthAwarePaginator
    {
        return new Paginator([], 0, 10, max(1, Paginator::resolveCurrentPage('issueNotificationPage')), ['path' => request()->url(), 'query' => request()->query(), 'pageName' => 'issueNotificationPage']);
    }
}
