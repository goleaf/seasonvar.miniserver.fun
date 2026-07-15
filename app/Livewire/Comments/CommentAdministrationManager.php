<?php

declare(strict_types=1);

namespace App\Livewire\Comments;

use App\Actions\Comments\ModerateComment;
use App\Actions\Comments\ResolveCommentReport;
use App\Actions\Comments\RestrictCommenter;
use App\Actions\Comments\RevokeCommentRestriction;
use App\Enums\CommentModerationReason;
use App\Enums\CommentReportStatus;
use App\Enums\CommentRestrictionReason;
use App\Enums\CommentRestrictionType;
use App\Enums\CommentStatus;
use App\Enums\CommentTargetType;
use App\Exceptions\Comments\CommentActionException;
use App\Models\Comment;
use App\Models\CommentReport;
use App\Models\CommentRestriction;
use App\Models\User;
use App\Services\Comments\CommentModerationQuery;
use App\Services\Comments\CommentSchema;
use App\Support\UserPlainText;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class CommentAdministrationManager extends Component
{
    use WithPagination;

    #[Url(history: true, except: 'attention')]
    public string $status = 'attention';

    #[Url(history: true, except: '')]
    public string $target = '';

    #[Url(as: 'user', history: true, except: '')]
    public string $author = '';

    #[Url(as: 'comment', history: true, except: null)]
    public ?int $selectedCommentId = null;

    public string $moderationStatus = 'published';

    public string $moderationReason = 'approved';

    public string $privateNote = '';

    public bool $resolveOpenReports = true;

    public string $restrictionType = 'temporary';

    public string $restrictionReason = 'spam';

    public int $restrictionDuration = 7;

    public string $restrictionNote = '';

    public ?string $notice = null;

    public ?string $actionError = null;

    public function mount(CommentModerationQuery $query): void
    {
        Gate::authorize('manage-comments');
        $this->normalizeFilters();

        if ($this->selectedCommentId !== null) {
            $selectedCommentId = $this->selectedCommentId;
            $this->selectedCommentId = null;

            if ($selectedCommentId > 0) {
                $this->openModeration($selectedCommentId, $query);
            }
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'target', 'author'], true)) {
            $this->normalizeFilters();
            $this->resetPage(pageName: 'moderation_page');
        }
    }

    public function clearFilters(): void
    {
        $this->status = 'attention';
        $this->target = '';
        $this->author = '';
        $this->resetPage(pageName: 'moderation_page');
    }

    public function openModeration(int $commentId, CommentModerationQuery $query): void
    {
        $comment = $this->attempt(fn (): Comment => $query->comment($commentId, $this->user()));

        if (! $comment instanceof Comment) {
            return;
        }

        $this->selectedCommentId = (int) $comment->id;
        $this->moderationStatus = $comment->status->value;
        $this->moderationReason = $comment->moderation_reason->value ?? CommentModerationReason::Approved->value;
        $this->privateNote = (string) ($comment->moderator_note ?? '');
        $this->restrictionType = CommentRestrictionType::Temporary->value;
        $this->restrictionReason = CommentRestrictionReason::Spam->value;
        $this->restrictionDuration = 7;
        $this->restrictionNote = '';
        $this->dispatch('comment-report-opened');
    }

    public function closeModeration(): void
    {
        $this->selectedCommentId = null;
        $this->privateNote = '';
        $this->restrictionNote = '';
        $this->resetValidation();
        $this->dispatch('comment-report-closed');
    }

    public function saveModeration(ModerateComment $moderate): void
    {
        $commentId = $this->selectedCommentId;

        if ($commentId === null) {
            $this->actionError = __('comments.errors.comment_not_found');

            return;
        }

        $comment = $this->attempt(fn (): Comment => $moderate->handle(
            $this->user(),
            $commentId,
            $this->moderationStatus,
            $this->moderationReason,
            $this->privateNote,
            $this->resolveOpenReports,
        ));

        if ($comment instanceof Comment) {
            $this->notice = __('comments.admin.saved');
            $this->closeModeration();
        }
    }

    public function applyRestriction(CommentModerationQuery $query, RestrictCommenter $restrict): void
    {
        $commentId = $this->selectedCommentId;

        if ($commentId === null) {
            $this->actionError = __('comments.errors.comment_not_found');

            return;
        }

        $restriction = $this->attempt(function () use ($query, $restrict, $commentId): CommentRestriction {
            $comment = $query->comment($commentId, $this->user());

            if (! is_int($comment->user_id)) {
                throw new CommentActionException('comments.errors.comment_not_found');
            }

            return $restrict->handle(
                $this->user(),
                $comment->user_id,
                $this->restrictionType,
                $this->restrictionReason,
                $this->restrictionType === CommentRestrictionType::Temporary->value
                    ? $this->restrictionDuration
                    : null,
                $this->restrictionNote,
            );
        });

        if ($restriction instanceof CommentRestriction) {
            $this->notice = __('comments.admin.restriction_saved');
        }
    }

    public function revokeRestriction(int $restrictionId, RevokeCommentRestriction $revoke): void
    {
        $restriction = $this->attempt(fn (): CommentRestriction => $revoke->handle($this->user(), $restrictionId));

        if ($restriction instanceof CommentRestriction) {
            $this->notice = __('comments.admin.restriction_revoked');
        }
    }

    public function resolveReport(int $reportId, string $status, ResolveCommentReport $resolve): void
    {
        $report = $this->attempt(fn (): CommentReport => $resolve->handle(
            $this->user(),
            $reportId,
            $status,
            $this->privateNote,
        ));

        if ($report instanceof CommentReport) {
            $this->notice = __('comments.admin.report_saved');
        }
    }

    public function render(CommentModerationQuery $query, CommentSchema $schema): View
    {
        $available = $schema->writable();
        $queryFailed = false;
        $comments = null;
        $selectedThread = null;

        if ($available) {
            try {
                $comments = $query->paginate($this->status, $this->target, $this->author);
                $selectedThread = $this->selectedCommentId !== null
                    ? $query->threadContext($this->selectedCommentId, $this->user())
                    : null;
            } catch (Throwable $exception) {
                report($exception);
                $queryFailed = true;
            }
        }

        return view('livewire.comments.comment-administration-manager', [
            'available' => $available,
            'queryFailed' => $queryFailed,
            'comments' => $comments,
            'selectedThread' => $selectedThread,
            'statusOptions' => CommentStatus::cases(),
            'targetOptions' => CommentTargetType::cases(),
            'moderationReasons' => CommentModerationReason::cases(),
            'restrictionTypes' => CommentRestrictionType::cases(),
            'restrictionReasons' => CommentRestrictionReason::cases(),
            'restrictionDurations' => [1, 3, 7, 30, 90],
            'resolvedReportStatus' => CommentReportStatus::Resolved->value,
            'dismissedReportStatus' => CommentReportStatus::Dismissed->value,
        ])
            ->extends('layouts.app', [
                'title' => __('comments.admin.title'),
                'seo' => [
                    'title' => __('comments.admin.title'),
                    'description' => __('comments.admin.description'),
                    'robots' => 'noindex, nofollow',
                    'canonical' => route('admin.comments'),
                    'social' => false,
                ],
            ])
            ->section('content');
    }

    private function normalizeFilters(): void
    {
        if ($this->status !== 'attention' && $this->status !== '' && CommentStatus::tryFrom($this->status) === null) {
            $this->status = 'attention';
        }

        if ($this->target !== '' && CommentTargetType::tryFrom($this->target) === null) {
            $this->target = '';
        }

        $this->author = Str::limit(
            Str::replace(['\\', '%', '_'], '', UserPlainText::name($this->author)),
            120,
            '',
        );
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $user->can('manage-comments'), 403);

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
        } catch (AuthorizationException) {
            $this->actionError = __('comments.errors.forbidden');
        } catch (ModelNotFoundException) {
            $this->actionError = __('comments.errors.comment_not_found');
        } catch (Throwable $exception) {
            report($exception);
            $this->actionError = __('comments.errors.generic');
        }

        return null;
    }
}
