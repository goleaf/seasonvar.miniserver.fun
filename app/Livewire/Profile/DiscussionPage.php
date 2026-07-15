<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Actions\Comments\MarkCommentNotificationRead;
use App\Actions\Comments\SetUserBlock;
use App\Actions\Comments\SetUserMute;
use App\Actions\Reviews\MarkReviewNotificationRead;
use App\Exceptions\Comments\CommentActionException;
use App\Exceptions\Reviews\ReviewActionException;
use App\Models\User;
use App\Services\Comments\CommentProfileQuery;
use App\Services\Comments\CommentSchema;
use App\Services\Reviews\ReviewNotificationQuery;
use App\Services\Reviews\ReviewSchema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class DiscussionPage extends Component
{
    use WithPagination;

    public ?string $notice = null;

    public ?string $actionError = null;

    public function markNotificationRead(string $notificationId, MarkCommentNotificationRead $notifications): void
    {
        $this->attempt(function () use ($notificationId, $notifications): bool {
            $notifications->one($this->user(), $notificationId);

            return true;
        });
    }

    public function markAllNotificationsRead(MarkCommentNotificationRead $notifications): void
    {
        $result = $this->attempt(function () use ($notifications): bool {
            $notifications->all($this->user());

            return true;
        });

        if ($result === true) {
            $this->notice = __('comments.notifications.marked_all_read');
        }
    }

    public function markReviewNotificationRead(
        string $notificationId,
        MarkReviewNotificationRead $notifications,
    ): void {
        $this->attempt(function () use ($notificationId, $notifications): bool {
            $notifications->one($this->user(), $notificationId);

            return true;
        });
    }

    public function markAllReviewNotificationsRead(MarkReviewNotificationRead $notifications): void
    {
        $result = $this->attempt(function () use ($notifications): bool {
            $notifications->all($this->user());

            return true;
        });

        if ($result === true) {
            $this->notice = __('reviews.notifications.marked_all_read');
        }
    }

    public function unblock(int $userId, SetUserBlock $blocks): void
    {
        $result = $this->attempt(function () use ($userId, $blocks): bool {
            $blocks->handle($this->user(), $userId, false);

            return true;
        });

        if ($result === true) {
            $this->resetPage(pageName: 'blocks_page');
            $this->notice = __('comments.success.unblocked');
        }
    }

    public function unmute(int $userId, SetUserMute $mutes): void
    {
        $result = $this->attempt(function () use ($userId, $mutes): bool {
            $mutes->handle($this->user(), $userId, false);

            return true;
        });

        if ($result === true) {
            $this->resetPage(pageName: 'mutes_page');
            $this->notice = __('comments.success.unmuted');
        }
    }

    public function render(
        CommentProfileQuery $query,
        CommentSchema $schema,
        ReviewNotificationQuery $reviewNotifications,
        ReviewSchema $reviewSchema,
    ): View {
        $available = $schema->writable();
        $notificationsAvailable = $schema->notificationsAvailable();
        $reviewNotificationsAvailable = false;
        $reviewNotificationsFailed = false;
        $reviewNotificationItems = null;
        $queryFailed = false;
        $activity = null;
        $notifications = null;
        $blocks = null;
        $mutes = null;

        if ($available) {
            try {
                $activity = $query->activity($this->user());
                $notifications = $notificationsAvailable ? $query->notifications($this->user()) : null;
                $blocks = $query->blocks($this->user());
                $mutes = $query->mutes($this->user());
            } catch (Throwable $exception) {
                report($exception);
                $queryFailed = true;
            }
        }

        try {
            $reviewNotificationsAvailable = $reviewSchema->notificationsAvailable();
            $reviewNotificationItems = $reviewNotificationsAvailable
                ? $reviewNotifications->forUser($this->user())
                : null;
        } catch (Throwable $exception) {
            report($exception);
            $reviewNotificationsFailed = true;
        }

        return view('livewire.profile.discussion-page', [
            'available' => $available,
            'queryFailed' => $queryFailed,
            'notificationsAvailable' => $notificationsAvailable,
            'activity' => $activity,
            'notifications' => $notifications,
            'reviewNotificationsAvailable' => $reviewNotificationsAvailable,
            'reviewNotificationsFailed' => $reviewNotificationsFailed,
            'reviewNotifications' => $reviewNotificationItems,
            'blocks' => $blocks,
            'mutes' => $mutes,
        ])
            ->extends('layouts.app', [
                'title' => __('comments.profile.title'),
                'seo' => [
                    'title' => __('comments.profile.title'),
                    'description' => __('comments.profile.description'),
                    'robots' => 'noindex, nofollow',
                    'canonical' => route('profile.discussions'),
                    'social' => false,
                ],
            ])
            ->section('content');
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function attempt(callable $action): mixed
    {
        $this->notice = null;
        $this->actionError = null;

        try {
            return $action();
        } catch (CommentActionException $exception) {
            $this->actionError = $exception->localizedMessage();
        } catch (ReviewActionException $exception) {
            $this->actionError = __($exception->translationKey, $exception->replace);
        } catch (ModelNotFoundException) {
            $this->actionError = __('comments.errors.comment_not_found');
        } catch (Throwable $exception) {
            report($exception);
            $this->actionError = __('comments.errors.generic');
        }

        return null;
    }
}
