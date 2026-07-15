<?php

declare(strict_types=1);

namespace App\Livewire\Comments;

use App\Actions\Comments\CreateComment;
use App\Actions\Comments\CreateReply;
use App\Actions\Comments\DeleteComment;
use App\Actions\Comments\ReportComment;
use App\Actions\Comments\RestoreComment;
use App\Actions\Comments\SetCommentReaction;
use App\Actions\Comments\SetUserBlock;
use App\Actions\Comments\SetUserMute;
use App\Actions\Comments\UpdateComment;
use App\DTOs\Comments\CommentScopeData;
use App\Enums\CommentReactionType;
use App\Enums\CommentReportCategory;
use App\Enums\CommentSort;
use App\Enums\CommentStatus;
use App\Enums\CommentTargetType;
use App\Exceptions\Comments\CommentActionException;
use App\Models\Comment;
use App\Models\User;
use App\Services\Comments\CommentDiscussionQuery;
use App\Services\Comments\CommentRestrictionService;
use App\Services\Comments\CommentSchema;
use App\Services\Comments\CommentTargetResolver;
use App\ValueObjects\CommentTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class CommentDiscussion extends Component
{
    use WithPagination;

    #[Locked]
    public string $baseTargetType = '';

    #[Locked]
    public int $baseTargetId = 0;

    #[Locked]
    public string $targetType = '';

    #[Locked]
    public int $targetId = 0;

    #[Locked]
    public ?int $catalogTitleId = null;

    #[Locked]
    public ?string $interfaceLocale = null;

    #[Locked]
    public ?int $selectedSeasonId = null;

    #[Locked]
    public ?int $selectedEpisodeId = null;

    #[Url(as: 'discussion_scope', history: true, except: '')]
    public string $scope = '';

    #[Url(as: 'discussion_sort', history: true, except: 'newest')]
    public string $sort = 'newest';

    #[Url(as: 'comment', except: null)]
    public ?int $focusedCommentId = null;

    #[Url(as: 'thread', except: null)]
    public ?int $expandedThreadId = null;

    #[Locked]
    public int $replyLimit = 20;

    /** @var list<int> */
    #[Locked]
    public array $revealedSpoilers = [];

    /** @var list<int> */
    #[Locked]
    public array $expandedBodies = [];

    public string $body = '';

    public bool $isSpoiler = false;

    /** @var array<string, array{body: string, is_spoiler: bool}> */
    #[Locked]
    public array $scopeDrafts = [];

    #[Locked]
    public string $submissionToken = '';

    public ?int $replyToCommentId = null;

    public string $replyBody = '';

    public bool $replyIsSpoiler = false;

    #[Locked]
    public string $replySubmissionToken = '';

    public ?int $editingCommentId = null;

    public string $editBody = '';

    public bool $editIsSpoiler = false;

    #[Locked]
    public int $editVersion = 0;

    public ?int $reportingCommentId = null;

    public string $reportCategory = 'spam';

    public string $reportDetails = '';

    public ?string $notice = null;

    public ?string $actionError = null;

    public function mount(
        string $targetType,
        int $targetId,
        CommentTargetResolver $targets,
        CommentSchema $schema,
        ?string $interfaceLocale = null,
    ): void {
        $this->baseTargetType = $targetType;
        $this->baseTargetId = $targetId;
        $this->submissionToken = (string) Str::uuid();
        $this->replySubmissionToken = (string) Str::uuid();
        $this->replyLimit = max(1, (int) config('comments.pagination.replies_per_page', 20));
        $this->interfaceLocale = in_array($interfaceLocale, config('catalog-collections.supported_locales', []), true)
            ? $interfaceLocale
            : null;
        $this->applyInterfaceLocale();
        $this->normalizeSort();

        $base = $targets->resolve($targetType, $targetId, $this->viewer(), $this->interfaceLocale);
        $this->catalogTitleId = $base->catalogTitleId;
        $this->applyTarget($base, updateScope: false);

        if ($base->type === CommentTargetType::Title) {
            $this->initializeTitleScope($targets);
        }

        if ($schema->writable()) {
            $this->normalizeDirectContext();
        } else {
            $this->focusedCommentId = null;
            $this->expandedThreadId = null;
        }
    }

    public function hydrate(): void
    {
        $this->applyInterfaceLocale();
    }

    public function updatedSort(): void
    {
        $this->normalizeSort();
        $this->resetPage(pageName: 'comments_page');
        $this->expandedThreadId = null;
    }

    public function updatedScope(CommentTargetResolver $targets): void
    {
        $this->selectScopeValue($this->scope, $targets);
    }

    #[On('discussion-target-selected')]
    public function syncPlayerTarget(
        int $catalogTitleId,
        ?int $seasonId,
        ?int $episodeId,
        CommentTargetResolver $targets,
    ): void {
        if ($this->baseTargetType !== CommentTargetType::Title->value
            || $this->catalogTitleId !== $catalogTitleId) {
            return;
        }

        $this->selectedSeasonId = $seasonId;
        $this->selectedEpisodeId = $episodeId;
        $type = $episodeId !== null
            ? CommentTargetType::Episode
            : ($seasonId !== null ? CommentTargetType::Season : CommentTargetType::Title);
        $id = $episodeId ?? $seasonId ?? $this->baseTargetId;
        $this->selectResolvedTarget($type, $id, $targets);
    }

    public function selectScope(string $type, CommentTargetResolver $targets): void
    {
        $targetType = CommentTargetType::tryFrom($type);
        $targetId = match ($targetType) {
            CommentTargetType::Title => $this->baseTargetId,
            CommentTargetType::Season => $this->selectedSeasonId,
            CommentTargetType::Episode => $this->selectedEpisodeId,
            CommentTargetType::Collection => $this->baseTargetType === CommentTargetType::Collection->value
                ? $this->baseTargetId
                : null,
            default => null,
        };

        if ($targetType === null || $targetId === null) {
            $this->actionError = __('comments.errors.target_unavailable');

            return;
        }

        $this->selectResolvedTarget($targetType, $targetId, $targets);
    }

    public function publish(CreateComment $create, CommentSchema $schema): void
    {
        $comment = $this->attempt(function () use ($create, $schema): Comment {
            $this->assertWritable($schema);

            return $create->handle(
                $this->authenticatedUser(),
                $this->targetType,
                $this->targetId,
                $this->body,
                $this->isSpoiler,
                $this->submissionToken,
            );
        });

        if (! $comment instanceof Comment) {
            return;
        }

        $this->body = '';
        $this->isSpoiler = false;
        unset($this->scopeDrafts[$this->targetType.':'.$this->targetId]);
        $this->submissionToken = (string) Str::uuid();
        $this->focusedCommentId = (int) $comment->id;
        $this->sort = CommentSort::Newest->value;
        $this->resetPage(pageName: 'comments_page');
        $this->notice = $comment->status === CommentStatus::Pending
            ? __('comments.success.pending')
            : __('comments.success.created');
        $this->dispatch('comment-action-completed', selector: '#comment-'.$comment->id);
    }

    public function beginReply(int $commentId): void
    {
        $comment = $this->attempt(fn (): Comment => $this->authorizedComment($commentId, 'reply'));

        if (! $comment instanceof Comment) {
            return;
        }

        $this->replyToCommentId = (int) $comment->id;
        $this->replyBody = '';
        $this->replyIsSpoiler = false;
        $this->replySubmissionToken = (string) Str::uuid();
        $this->editingCommentId = null;
        $this->reportingCommentId = null;
        $this->dispatch('comment-editor-opened', selector: '#comment-reply-'.$comment->id);
    }

    public function cancelReply(): void
    {
        $commentId = $this->replyToCommentId;
        $this->replyToCommentId = null;
        $this->replyBody = '';
        $this->replyIsSpoiler = false;
        $this->resetValidation();

        if ($commentId !== null) {
            $this->dispatch('comment-action-completed', selector: '#comment-'.$commentId);
        }
    }

    public function publishReply(CreateReply $create, CommentSchema $schema): void
    {
        $replyToId = $this->replyToCommentId;

        if ($replyToId === null) {
            $this->actionError = __('comments.errors.invalid_parent');

            return;
        }

        $comment = $this->attempt(function () use ($create, $schema, $replyToId): Comment {
            $this->assertWritable($schema);

            return $create->handle(
                $this->authenticatedUser(),
                $this->targetType,
                $this->targetId,
                $replyToId,
                $this->replyBody,
                $this->replyIsSpoiler,
                $this->replySubmissionToken,
            );
        });

        if (! $comment instanceof Comment) {
            return;
        }

        $this->replyToCommentId = null;
        $this->replyBody = '';
        $this->replyIsSpoiler = false;
        $this->replySubmissionToken = (string) Str::uuid();
        $this->expandedThreadId = (int) ($comment->parent_id ?? $comment->id);
        $this->focusedCommentId = (int) $comment->id;
        $this->notice = $comment->status === CommentStatus::Pending
            ? __('comments.success.pending')
            : __('comments.success.reply_created');
        $this->dispatch('comment-action-completed', selector: '#comment-'.$comment->id);
    }

    public function beginEdit(int $commentId): void
    {
        $comment = $this->attempt(fn (): Comment => $this->authorizedComment($commentId, 'update'));

        if (! $comment instanceof Comment) {
            return;
        }

        $this->editingCommentId = (int) $comment->id;
        $this->editBody = (string) $comment->body;
        $this->editIsSpoiler = (bool) $comment->is_spoiler;
        $this->editVersion = (int) $comment->version;
        $this->replyToCommentId = null;
        $this->reportingCommentId = null;
        $this->dispatch('comment-editor-opened', selector: '#comment-edit-'.$comment->id);
    }

    public function cancelEdit(): void
    {
        $commentId = $this->editingCommentId;
        $this->editingCommentId = null;
        $this->editBody = '';
        $this->editIsSpoiler = false;
        $this->editVersion = 0;
        $this->resetValidation();

        if ($commentId !== null) {
            $this->dispatch('comment-action-completed', selector: '#comment-'.$commentId);
        }
    }

    public function saveEdit(UpdateComment $update, CommentSchema $schema): void
    {
        $commentId = $this->editingCommentId;

        if ($commentId === null) {
            $this->actionError = __('comments.errors.comment_not_found');

            return;
        }

        $comment = $this->attempt(function () use ($update, $schema, $commentId): Comment {
            $this->assertWritable($schema);

            return $update->handle(
                $this->authenticatedUser(),
                $commentId,
                $this->editVersion,
                $this->editBody,
                $this->editIsSpoiler,
            );
        });

        if (! $comment instanceof Comment) {
            return;
        }

        $this->cancelEdit();
        $this->notice = __('comments.success.updated');
    }

    public function deleteComment(int $commentId, DeleteComment $delete, CommentSchema $schema): void
    {
        $comment = $this->attempt(function () use ($delete, $schema, $commentId): Comment {
            $this->assertWritable($schema);

            return $delete->handle($this->authenticatedUser(), $commentId);
        });

        if ($comment instanceof Comment) {
            $this->notice = __('comments.success.deleted');
            $this->dispatch('comment-action-completed', selector: '#comment-'.$comment->id);
        }
    }

    public function restoreComment(int $commentId, RestoreComment $restore, CommentSchema $schema): void
    {
        $comment = $this->attempt(function () use ($restore, $schema, $commentId): Comment {
            $this->assertWritable($schema);

            return $restore->handle($this->authenticatedUser(), $commentId);
        });

        if ($comment instanceof Comment) {
            $this->notice = __('comments.success.restored');
            $this->dispatch('comment-action-completed', selector: '#comment-'.$comment->id);
        }
    }

    public function react(
        int $commentId,
        ?string $type,
        SetCommentReaction $reactions,
        CommentSchema $schema,
    ): void {
        $result = $this->attempt(function () use ($commentId, $type, $reactions, $schema): bool {
            $this->assertWritable($schema);
            $user = $this->authenticatedUser();
            $desired = $type === null ? null : CommentReactionType::tryFrom($type);

            if ($type !== null && $desired === null) {
                throw new CommentActionException('comments.errors.invalid_reaction');
            }

            $reactions->handle($user, $commentId, $desired);

            return true;
        });

        if ($result === true) {
            $this->notice = __('comments.success.reaction_updated');
        }
    }

    public function openReport(int $commentId): void
    {
        $comment = $this->attempt(fn (): Comment => $this->authorizedComment($commentId, 'report'));

        if (! $comment instanceof Comment) {
            return;
        }

        $this->reportingCommentId = (int) $comment->id;
        $this->reportCategory = CommentReportCategory::Spam->value;
        $this->reportDetails = '';
        $this->replyToCommentId = null;
        $this->editingCommentId = null;
        $this->dispatch('comment-report-opened');
    }

    public function closeReport(): void
    {
        $this->reportingCommentId = null;
        $this->reportDetails = '';
        $this->resetValidation();
        $this->dispatch('comment-report-closed');
    }

    public function submitReport(ReportComment $reports, CommentSchema $schema): void
    {
        $commentId = $this->reportingCommentId;

        if ($commentId === null) {
            $this->actionError = __('comments.errors.comment_not_found');

            return;
        }

        $result = $this->attempt(function () use ($reports, $schema, $commentId): bool {
            $this->assertWritable($schema);
            $reports->handle(
                $this->authenticatedUser(),
                $commentId,
                $this->reportCategory,
                $this->reportDetails,
            );

            return true;
        });

        if ($result === true) {
            $this->closeReport();
            $this->notice = __('comments.reports.submitted');
        }
    }

    public function blockAuthor(
        int $commentId,
        SetUserBlock $blocks,
        CommentSchema $schema,
    ): void {
        $result = $this->attempt(function () use ($commentId, $blocks, $schema): bool {
            $this->assertWritable($schema);
            $user = $this->authenticatedUser();
            $comment = $this->relationshipComment($commentId, $user);
            $authorId = $comment->user_id;

            if (! is_int($authorId)) {
                throw new CommentActionException('comments.errors.comment_not_found');
            }

            $blocks->handle($user, $authorId, true);

            return true;
        });

        if ($result === true) {
            $this->notice = __('comments.success.blocked');
            $this->dispatch('comment-action-completed', selector: '#comment-'.$commentId);
        }
    }

    public function muteAuthor(int $commentId, SetUserMute $mutes, CommentSchema $schema): void
    {
        $result = $this->attempt(function () use ($commentId, $mutes, $schema): bool {
            $this->assertWritable($schema);
            $user = $this->authenticatedUser();
            $comment = $this->relationshipComment($commentId, $user);
            $authorId = $comment->user_id;

            if (! is_int($authorId)) {
                throw new CommentActionException('comments.errors.comment_not_found');
            }

            $mutes->handle($user, $authorId, true);

            return true;
        });

        if ($result === true) {
            $this->notice = __('comments.success.muted');
            $this->dispatch('comment-action-completed', selector: '#comment-'.$commentId);
        }
    }

    public function toggleSpoiler(int $commentId): void
    {
        $this->revealedSpoilers = $this->toggleId($this->revealedSpoilers, $commentId);
        $this->dispatch('comment-spoiler-toggled', selector: '#comment-'.$commentId);
    }

    public function toggleBody(int $commentId): void
    {
        $this->expandedBodies = $this->toggleId($this->expandedBodies, $commentId);
    }

    public function toggleThread(int $commentId): void
    {
        $root = $this->attempt(fn (): Comment => $this->threadRoot($commentId));

        if (! $root instanceof Comment) {
            return;
        }

        $this->expandedThreadId = $this->expandedThreadId === (int) $root->id
            ? null
            : (int) $root->id;
        $this->replyLimit = max(1, (int) config('comments.pagination.replies_per_page', 20));
    }

    public function loadMoreReplies(): void
    {
        if ($this->expandedThreadId === null) {
            return;
        }

        $increment = max(1, (int) config('comments.pagination.replies_per_page', 20));
        $this->replyLimit = min(PHP_INT_MAX - $increment, $this->replyLimit) + $increment;
    }

    public function render(
        CommentTargetResolver $targets,
        CommentDiscussionQuery $discussion,
        CommentRestrictionService $restrictions,
        CommentSchema $schema,
    ): View {
        $available = $schema->writable();
        $queryFailed = false;
        $target = null;
        $comments = null;
        $replies = collect();
        $publicCount = 0;
        $hasMoreReplies = false;
        $scopeOptions = [];

        if ($available) {
            try {
                $target = $targets->resolve($this->targetType, $this->targetId, $this->viewer(), $this->interfaceLocale);
                $scopeOptions = $this->scopeOptions($targets, $target);
                $comments = $discussion->comments(
                    $target,
                    $this->viewer(),
                    CommentSort::from($this->sort),
                    $this->revealedSpoilers,
                    $this->expandedBodies,
                    $this->focusedCommentId,
                    $this->interfaceLocale,
                );
                $publicCount = $discussion->publicCount($target);

                if ($this->expandedThreadId !== null) {
                    $this->threadRoot($this->expandedThreadId);
                    $replies = $discussion->replies(
                        $target,
                        $this->expandedThreadId,
                        $this->viewer(),
                        $this->replyLimit,
                        $this->revealedSpoilers,
                        $this->expandedBodies,
                        $this->focusedCommentId,
                        $this->interfaceLocale,
                    );
                    $expandedRoot = $comments->getCollection()
                        ->first(fn (mixed $item): bool => $item->id === $this->expandedThreadId);
                    $hasMoreReplies = $expandedRoot !== null
                        && $replies->count() < $expandedRoot->visibleReplyCount;
                }
            } catch (Throwable $exception) {
                report($exception);
                $queryFailed = true;
            }
        }

        $viewer = $this->viewer();
        $restriction = null;

        if ($viewer !== null && $available && ! $queryFailed) {
            try {
                $restriction = $restrictions->activeFor($viewer);
            } catch (Throwable $exception) {
                report($exception);
                $queryFailed = true;
            }
        }

        $canCompose = ! $queryFailed
            && $viewer !== null
            && $restriction === null
            && Gate::forUser($viewer)->allows('create', Comment::class);

        return view('livewire.comments.comment-discussion', [
            'available' => $available,
            'queryFailed' => $queryFailed,
            'activeTarget' => $target,
            'scopeOptions' => $scopeOptions,
            'comments' => $comments,
            'replies' => $replies,
            'hasMoreReplies' => $hasMoreReplies,
            'publicCount' => $publicCount,
            'sortOptions' => CommentSort::cases(),
            'reportCategories' => CommentReportCategory::cases(),
            'isAuthenticated' => $viewer !== null,
            'isVerified' => $viewer?->hasVerifiedEmail() === true,
            'canCompose' => $canCompose,
            'restrictionMessage' => $restriction === null
                ? null
                : ($restriction->expires_at === null
                    ? __('comments.restrictions.active_permanent', ['reason' => $restriction->reason_code->label()])
                    : __('comments.restrictions.active_until', [
                        'reason' => $restriction->reason_code->label(),
                        'expires' => $restriction->expires_at->translatedFormat('d.m.Y H:i'),
                    ])),
            'maximumLength' => max(1, (int) config('comments.body.maximum_length', 5_000)),
            'bodyLength' => mb_strlen($this->body),
            'showScopeSelector' => count($scopeOptions) > 1,
        ]);
    }

    private function initializeTitleScope(CommentTargetResolver $targets): void
    {
        if ($this->scope !== '') {
            $this->selectScopeValue($this->scope, $targets, resetPage: false);

            return;
        }

        $episodeId = $this->positiveId(request()->query('episode'));
        $seasonId = $this->positiveId(request()->query('season'));

        if ($episodeId !== null && $this->selectResolvedTarget(CommentTargetType::Episode, $episodeId, $targets, false)) {
            return;
        }

        if ($seasonId !== null) {
            $this->selectResolvedTarget(CommentTargetType::Season, $seasonId, $targets, false);
        }
    }

    private function selectScopeValue(
        string $scope,
        CommentTargetResolver $targets,
        bool $resetPage = true,
    ): void {
        if (preg_match('/^(title|season|episode|collection):(\d+)$/D', $scope, $matches) !== 1) {
            $this->applyBaseTarget($targets, $resetPage);

            return;
        }

        $type = CommentTargetType::from($matches[1]);
        $id = (int) $matches[2];

        if (! $this->selectResolvedTarget($type, $id, $targets, $resetPage)) {
            $this->applyBaseTarget($targets, $resetPage);
        }
    }

    private function selectResolvedTarget(
        CommentTargetType $type,
        int $id,
        CommentTargetResolver $targets,
        bool $resetPage = true,
    ): bool {
        try {
            $target = $targets->resolve($type, $id, $this->viewer(), $this->interfaceLocale);
        } catch (ModelNotFoundException|AuthorizationException) {
            return false;
        }

        if ($this->baseTargetType === CommentTargetType::Title->value
            && $target->catalogTitleId !== $this->catalogTitleId) {
            return false;
        }

        if ($this->baseTargetType === CommentTargetType::Collection->value
            && ($target->type !== CommentTargetType::Collection || $target->id !== $this->baseTargetId)) {
            return false;
        }

        $this->applyTarget($target);

        if ($resetPage) {
            $this->resetDiscussionState();
        }

        return true;
    }

    private function applyBaseTarget(CommentTargetResolver $targets, bool $resetPage): void
    {
        $target = $targets->resolve($this->baseTargetType, $this->baseTargetId, $this->viewer(), $this->interfaceLocale);
        $this->applyTarget($target);

        if ($resetPage) {
            $this->resetDiscussionState();
        }
    }

    private function applyTarget(CommentTarget $target, bool $updateScope = true): void
    {
        $previousKey = $this->targetType !== '' && $this->targetId > 0
            ? $this->targetType.':'.$this->targetId
            : null;
        $nextKey = $target->key();

        if ($previousKey !== null && $previousKey !== $nextKey) {
            if ($this->body !== '') {
                $this->scopeDrafts[$previousKey] = [
                    'body' => $this->body,
                    'is_spoiler' => $this->isSpoiler,
                ];
                $this->scopeDrafts = array_slice($this->scopeDrafts, -3, null, true);
            } else {
                unset($this->scopeDrafts[$previousKey]);
            }

            $draft = $this->scopeDrafts[$nextKey] ?? null;
            $this->body = $draft['body'] ?? '';
            $this->isSpoiler = $draft['is_spoiler'] ?? false;
        }

        $this->targetType = $target->type->value;
        $this->targetId = $target->id;
        $this->selectedSeasonId = $target->seasonId ?? $this->selectedSeasonId;
        $this->selectedEpisodeId = $target->episodeId ?? $this->selectedEpisodeId;

        if ($updateScope) {
            $this->scope = $target->type === CommentTargetType::Title
                && $target->id === $this->baseTargetId
                    ? ''
                    : $target->key();
        }
    }

    private function resetDiscussionState(): void
    {
        $this->resetPage(pageName: 'comments_page');
        $this->expandedThreadId = null;
        $this->focusedCommentId = null;
        $this->revealedSpoilers = [];
        $this->expandedBodies = [];
        $this->cancelReply();
        $this->cancelEdit();
        $this->closeReport();
    }

    /** @return list<CommentScopeData> */
    private function scopeOptions(CommentTargetResolver $targets, CommentTarget $active): array
    {
        if ($this->baseTargetType === CommentTargetType::Collection->value) {
            return [new CommentScopeData($active->type, $active->id, $active->label, true)];
        }

        $options = [];
        $base = $targets->resolve(CommentTargetType::Title, $this->baseTargetId, $this->viewer(), $this->interfaceLocale);
        $options[] = new CommentScopeData(
            $base->type,
            $base->id,
            CommentTargetType::Title->label(),
            $active->type === CommentTargetType::Title,
        );

        foreach ([
            CommentTargetType::Season->value => $this->selectedSeasonId,
            CommentTargetType::Episode->value => $this->selectedEpisodeId,
        ] as $type => $id) {
            if ($id === null) {
                continue;
            }

            try {
                $target = $targets->resolve($type, $id, $this->viewer(), $this->interfaceLocale);
            } catch (ModelNotFoundException|AuthorizationException) {
                continue;
            }

            if ($target->catalogTitleId !== $this->catalogTitleId) {
                continue;
            }

            $options[] = new CommentScopeData(
                $target->type,
                $target->id,
                $target->label,
                $target->type === $active->type && $target->id === $active->id,
            );
        }

        return $options;
    }

    private function normalizeDirectContext(): void
    {
        if ($this->focusedCommentId !== null && $this->focusedCommentId < 1) {
            $this->focusedCommentId = null;
        }

        if ($this->expandedThreadId !== null) {
            try {
                $root = $this->threadRoot($this->expandedThreadId);
                $this->expandedThreadId = (int) $root->id;
            } catch (Throwable) {
                $this->expandedThreadId = null;
            }
        }
    }

    private function normalizeSort(): void
    {
        if (CommentSort::tryFrom($this->sort) === null) {
            $this->sort = CommentSort::Newest->value;
        }
    }

    private function applyInterfaceLocale(): void
    {
        if ($this->interfaceLocale !== null
            && in_array($this->interfaceLocale, config('catalog-collections.supported_locales', []), true)) {
            App::setLocale($this->interfaceLocale);
        }
    }

    private function authorizedComment(int $commentId, string $ability): Comment
    {
        $comment = $this->commentForCurrentTarget($commentId);
        Gate::forUser($this->authenticatedUser())->authorize($ability, $comment);

        return $comment;
    }

    private function commentForCurrentTarget(int $commentId): Comment
    {
        if ($commentId < 1) {
            throw new CommentActionException('comments.errors.comment_not_found');
        }

        return Comment::query()
            ->withTrashed()
            ->where('target_type', $this->targetType)
            ->where('target_id', $this->targetId)
            ->findOrFail($commentId);
    }

    private function relationshipComment(int $commentId, User $user): Comment
    {
        $comment = $this->commentForCurrentTarget($commentId);

        if ($comment->status !== CommentStatus::Published
            || $comment->deleted_at !== null
            || $comment->user_id === null
            || $comment->user_id === $user->id) {
            throw new CommentActionException('comments.errors.comment_not_found');
        }

        return $comment;
    }

    private function threadRoot(int $commentId): Comment
    {
        return Comment::query()
            ->withTrashed()
            ->where('target_type', $this->targetType)
            ->where('target_id', $this->targetId)
            ->whereNull('parent_id')
            ->findOrFail($commentId);
    }

    private function assertWritable(CommentSchema $schema): void
    {
        if (! $schema->writable()) {
            throw new CommentActionException('comments.errors.action_unavailable');
        }
    }

    private function authenticatedUser(): User
    {
        $user = $this->viewer();

        if (! $user instanceof User) {
            throw new CommentActionException('comments.errors.authentication_required');
        }

        return $user;
    }

    private function viewer(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private function positiveId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0
            ? (int) $value
            : null;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function toggleId(array $ids, int $id): array
    {
        if ($id < 1) {
            return $ids;
        }

        if (in_array($id, $ids, true)) {
            return array_values(array_filter($ids, fn (int $stored): bool => $stored !== $id));
        }

        return array_values(array_unique([...array_slice($ids, -49), $id]));
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
