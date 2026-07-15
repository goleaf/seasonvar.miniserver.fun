<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\User;
use App\Services\Comments\CommentDiscussionQuery;
use App\Services\Comments\CommentSchema;
use App\Services\Comments\CommentTargetResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class CommentRedirectController extends Controller
{
    public function __invoke(
        Request $request,
        string $comment,
        CommentSchema $schema,
        CommentTargetResolver $targets,
        CommentDiscussionQuery $discussion,
    ): RedirectResponse {
        abort_unless($schema->writable(), 404);
        abort_unless(ctype_digit($comment) && (int) $comment > 0, 404);
        $viewer = $request->user();
        $viewer = $viewer instanceof User ? $viewer : null;
        $locale = $request->route('locale');
        $locale = is_string($locale) ? $locale : null;
        $commentModel = Comment::query()->withTrashed()->findOrFail((int) $comment);
        abort_unless(Gate::forUser($viewer)->allows('view', $commentModel), 404);
        $isModerator = $viewer !== null && Gate::forUser($viewer)->allows('manage-comments');

        if ($isModerator
            && ($commentModel->status !== CommentStatus::Published || $commentModel->deleted_at !== null)) {
            return $this->moderationRedirect($commentModel);
        }

        try {
            $target = $targets->fromComment($commentModel, $viewer, $locale);
        } catch (ModelNotFoundException) {
            abort_unless($isModerator, 404);

            return $this->moderationRedirect($commentModel);
        }
        $root = $discussion->rootFor($commentModel);
        $page = $discussion->oldestPageFor($target, $root, $viewer);
        $canonical = explode('#', $target->canonicalUrl, 2)[0];
        $separator = str_contains($canonical, '?') ? '&' : '?';
        $query = http_build_query(array_filter([
            'discussion_scope' => $target->type->value.':'.$target->id,
            'discussion_sort' => 'oldest',
            'comments_page' => $page > 1 ? $page : null,
            'thread' => $commentModel->parent_id !== null ? (int) $root->id : null,
            'comment' => (int) $commentModel->id,
        ], static fn (mixed $value): bool => $value !== null));

        return redirect()->to($canonical.$separator.$query.'#comment-'.$commentModel->id)
            ->withHeaders([
                'Cache-Control' => 'private, no-store',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
    }

    private function moderationRedirect(Comment $comment): RedirectResponse
    {
        return redirect()->route('admin.comments', ['comment' => $comment->id])
            ->withHeaders([
                'Cache-Control' => 'private, no-store',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
    }
}
