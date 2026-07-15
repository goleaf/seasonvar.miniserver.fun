<?php

declare(strict_types=1);

namespace App\Services\Comments;

use App\DTOs\Comments\CommentAuthorData;
use App\DTOs\Comments\CommentItemData;
use App\DTOs\Comments\CommentReactionSummaryData;
use App\DTOs\Comments\CommentViewerContext;
use App\Enums\CommentDeletionReason;
use App\Enums\CommentReactionType;
use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

final class CommentPresenter
{
    /**
     * @param  list<int>  $revealedSpoilers
     * @param  list<int>  $expandedBodies
     * @param  array<int, CommentReactionType>  $viewerReactions
     */
    public function item(
        Comment $comment,
        ?User $viewer,
        CommentViewerContext $context,
        array $revealedSpoilers,
        array $expandedBodies,
        array $viewerReactions,
        ?int $focusedCommentId,
        ?string $interfaceLocale = null,
    ): CommentItemData {
        $authorId = is_int($comment->user_id) ? $comment->user_id : null;
        $hiddenByViewer = ! $context->isModerator && $context->hides($authorId);
        $isOwner = $viewer !== null && $authorId === (int) $viewer->id;
        $isPublished = $comment->status === CommentStatus::Published;
        $isDeleted = $comment->deleted_at !== null;
        $ownerMayReadPrivate = $isOwner
            && ! $isPublished
            && $comment->status !== CommentStatus::Removed
            && ! $isDeleted;
        $bodyMayBeRead = ! $hiddenByViewer && ! $isDeleted && ($isPublished || $ownerMayReadPrivate);
        $spoilerRevealed = $comment->is_spoiler && in_array((int) $comment->id, $revealedSpoilers, true);
        $bodyExpanded = in_array((int) $comment->id, $expandedBodies, true);
        $body = $bodyMayBeRead && (! $comment->is_spoiler || $spoilerRevealed)
            ? (string) $comment->body
            : null;
        $collapseAfter = max(1, (int) config('comments.body.collapse_after', 700));
        $isLong = $body !== null && Str::length($body) > $collapseAfter;

        if ($isLong && ! $bodyExpanded) {
            $body = Str::limit($body, $collapseAfter);
        }

        $authorUnavailable = $hiddenByViewer || $isDeleted || $comment->author === null;
        $author = new CommentAuthorData(
            id: $authorUnavailable ? null : $authorId,
            name: $authorUnavailable
                ? __('comments.author.unavailable')
                : (string) $comment->author->name,
            isUnavailable: $authorUnavailable,
        );
        $replyToAuthor = $this->replyToAuthor($comment, $context);
        $unavailableMessage = match (true) {
            $hiddenByViewer => __('comments.states.hidden_by_preference'),
            $isDeleted => __('comments.states.deleted_comment'),
            ! $isPublished && ! $ownerMayReadPrivate => __('comments.states.comment_unavailable'),
            default => null,
        };
        $moderationLabel = $isOwner && $comment->status !== CommentStatus::Published
            ? $comment->status->label()
            : null;
        $viewerReaction = $viewerReactions[(int) $comment->id] ?? null;
        $up = max(0, (int) $comment->getAttribute('upvotes_count'));
        $down = max(0, (int) $comment->getAttribute('downvotes_count'));
        $replyCount = max(0, (int) $comment->getAttribute('replies_count'));
        $visibleReplyCount = $replyCount + max(0, (int) $comment->getAttribute('viewer_private_replies_count'));
        $threadOpen = $comment->parent_id === null
            || ($comment->parent !== null
                && $comment->parent->status === CommentStatus::Published
                && ($comment->parent->deleted_at === null
                    || $comment->parent->deletion_reason === CommentDeletionReason::Author));
        $canReply = $viewer !== null
            && $threadOpen
            && Gate::forUser($viewer)->allows('reply', $comment)
            && ! $hiddenByViewer;
        $canReact = $viewer !== null && Gate::forUser($viewer)->allows('react', $comment) && ! $hiddenByViewer;
        $canReport = $viewer !== null && Gate::forUser($viewer)->allows('report', $comment) && ! $hiddenByViewer;
        $canManageRelationship = $viewer !== null
            && $authorId !== null
            && $authorId !== (int) $viewer->id
            && ! $isDeleted
            && ! $hiddenByViewer;

        return new CommentItemData(
            id: (int) $comment->id,
            parentId: is_int($comment->parent_id) ? $comment->parent_id : null,
            replyToId: is_int($comment->reply_to_id) ? $comment->reply_to_id : null,
            version: (int) $comment->version,
            author: $author,
            replyToAuthor: $replyToAuthor,
            body: $body,
            isSpoiler: (bool) $comment->is_spoiler,
            spoilerRevealed: $spoilerRevealed,
            isLong: $isLong,
            bodyExpanded: $bodyExpanded,
            isDeleted: $isDeleted,
            isHiddenByViewer: $hiddenByViewer,
            isUnavailable: $unavailableMessage !== null,
            unavailableMessage: $unavailableMessage,
            moderationLabel: $moderationLabel,
            createdAtIso: $comment->created_at?->toAtomString() ?? '',
            createdAtLabel: $comment->created_at?->diffForHumans() ?? '',
            editedAtLabel: $comment->edited_at?->diffForHumans(),
            replyCount: $replyCount,
            visibleReplyCount: $visibleReplyCount,
            reactions: new CommentReactionSummaryData($up, $down, $up - $down, $viewerReaction),
            canReply: $canReply,
            canEdit: $viewer !== null && Gate::forUser($viewer)->allows('update', $comment),
            canDelete: $viewer !== null && Gate::forUser($viewer)->allows('delete', $comment),
            canRestore: $viewer !== null && Gate::forUser($viewer)->allows('restore', $comment),
            canReact: $canReact,
            canReport: $canReport,
            canBlock: $canManageRelationship,
            canMute: $canManageRelationship,
            directUrl: Gate::forUser($viewer)->allows('view', $comment)
                ? $this->directUrl($comment, $interfaceLocale)
                : null,
            isFocused: $focusedCommentId === (int) $comment->id,
        );
    }

    private function directUrl(Comment $comment, ?string $interfaceLocale): string
    {
        if ($interfaceLocale !== null
            && in_array($interfaceLocale, config('catalog-collections.supported_locales', []), true)
            && Route::has('localized.comments.show')) {
            return route('localized.comments.show', [
                'locale' => $interfaceLocale,
                'comment' => $comment,
            ]);
        }

        return Route::has('comments.show')
            ? route('comments.show', $comment)
            : '#comment-'.$comment->id;
    }

    private function replyToAuthor(Comment $comment, CommentViewerContext $context): ?string
    {
        if ($comment->reply_to_id === null || $comment->replyTo === null) {
            return null;
        }

        $replyTo = $comment->replyTo;
        $authorId = is_int($replyTo->user_id) ? $replyTo->user_id : null;

        if ($replyTo->deleted_at !== null
            || $replyTo->status !== CommentStatus::Published
            || $context->hides($authorId)
            || $replyTo->author === null) {
            return __('comments.author.unavailable');
        }

        return (string) $replyTo->author->name;
    }
}
