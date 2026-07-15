<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Actions\Comments\MarkCommentNotificationRead;
use App\Actions\Comments\SetUserBlock;
use App\Actions\Comments\SetUserMute;
use App\Actions\Comments\UpdateCommentNotificationPreferences;
use App\Actions\Reviews\MarkReviewNotificationRead;
use App\Exceptions\Comments\CommentActionException;
use App\Exceptions\Reviews\ReviewActionException;
use App\Models\CommentNotificationPreference;
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

    public bool $replyNotifications = true;

    public bool $reactionNotifications = true;

    public bool $moderationNotifications = true;

    public bool $reportNotifications = true;

    public ?string $notice = null;

    public ?string $actionError = null;

    public function mount(CommentSchema $schema): void
    {
        if (! $schema->notificationsAvailable()) {
            return;
        }

        $preference = CommentNotificationPreference::query()
            ->where('user_id', $this->user()->id)
            ->first();

        if ($preference === null) {
            return;
        }

        $this->replyNotifications = $preference->reply_notifications;
        $this->reactionNotifications = $preference->reaction_notifications;
        $this->moderationNotifications = $preference->moderation_notifications;
        $this->reportNotifications = $preference->report_notifications;
    }

    public function savePreferences(UpdateCommentNotificationPreferences $preferences): void
    {
        $saved = $this->attempt(fn (): CommentNotificationPreference => $preferences->handle($this->user(), [
            'reply_notifications' => $this->replyNotifications,
            'reaction_notifications' => $this->reactionNotifications,
            'moderation_notifications' => $this->moderationNotifications,
            'report_notifications' => $this->reportNotifications,
        ]));

        if ($saved instanceof CommentNotificationPreference) {
            $this->notice = __('comments.notifications.saved');
        }
    }

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
        $reviewNotificationsAvailable = $reviewSchema->notificationsAvailable();

        return view('livewire.profile.discussion-page', [
            'available' => $available,
            'notificationsAvailable' => $notificationsAvailable,
            'activity' => $available ? $query->activity($this->user()) : null,
            'notifications' => $notificationsAvailable ? $query->notifications($this->user()) : null,
            'reviewNotificationsAvailable' => $reviewNotificationsAvailable,
            'reviewNotifications' => $reviewNotificationsAvailable
                ? $reviewNotifications->forUser($this->user())
                : null,
            'blocks' => $available ? $query->blocks($this->user()) : [],
            'mutes' => $available ? $query->mutes($this->user()) : [],
            'preferenceOptions' => [
                'replyNotifications' => __('comments.notifications.reply_preference'),
                'reactionNotifications' => __('comments.notifications.reaction_preference'),
                'moderationNotifications' => __('comments.notifications.moderation_preference'),
                'reportNotifications' => __('comments.notifications.report_preference'),
            ],
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
