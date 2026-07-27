<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CommentSort;
use App\Enums\CommentStatus;
use App\Enums\CommentTargetType;
use App\Livewire\Comments\CommentDiscussion;
use App\Models\CatalogTitle;
use App\Models\Comment;
use App\Models\User;
use App\Services\Comments\CommentDiscussionQuery;
use App\ValueObjects\CommentTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class CommentDiscussionGuestTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_discussion_keeps_the_same_presentation_projection_as_an_authenticated_viewer(): void
    {
        $title = CatalogTitle::factory()->create();
        $author = User::factory()->create();
        $root = $this->comment($title, $author, 'Корневой комментарий');
        $reply = $this->comment($title, $author, 'Ответ гостю', $root);
        $target = new CommentTarget(
            type: CommentTargetType::Title,
            id: (int) $title->id,
            catalogTitleId: (int) $title->id,
            label: $title->display_title,
            canonicalUrl: route('titles.show', $title),
        );
        $discussion = app(CommentDiscussionQuery::class);

        $comment = $discussion->comments($target, null, CommentSort::Newest)->sole();
        $replies = $discussion->replies($target, (int) $root->id, null, 10);

        $this->assertSame(1, $comment->visibleReplyCount);
        $this->assertSame([$reply->id], $replies->pluck('id')->all());
        $this->assertSame(0, $replies->sole()->visibleReplyCount);

        Livewire::test(CommentDiscussion::class, [
            'targetType' => CommentTargetType::Title->value,
            'targetId' => $title->id,
        ])
            ->assertSee('data-comment-discussion', false)
            ->assertSeeText('Корневой комментарий')
            ->assertDontSeeText('Не удалось загрузить обсуждение. Обновите страницу.');
    }

    private function comment(
        CatalogTitle $title,
        User $author,
        string $body,
        ?Comment $parent = null,
    ): Comment {
        return Comment::query()->create([
            'user_id' => $author->id,
            'target_type' => CommentTargetType::Title,
            'target_id' => $title->id,
            'catalog_title_id' => $title->id,
            'parent_id' => $parent?->id,
            'reply_to_id' => $parent?->id,
            'body' => $body,
            'body_hash' => hash('sha256', $body),
            'status' => CommentStatus::Published,
        ]);
    }
}
