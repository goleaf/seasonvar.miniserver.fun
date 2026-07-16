<?php

declare(strict_types=1);

namespace Tests\Feature\DemoData;

use App\DTOs\DemoData\DemoDataOptions;
use App\Enums\CommentStatus;
use App\Enums\CommentTargetType;
use App\Enums\ReviewOrigin;
use App\Enums\ReviewStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleReview;
use App\Models\Comment;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\DemoData\Stages\DemoAccountStage;
use App\Services\DemoData\Stages\DemoCatalogActivityStage;
use App\Services\DemoData\Stages\DemoCommunityStage;
use App\Services\DemoData\Stages\DemoOrganizationStage;
use App\ValueObjects\CommentBody;
use App\ValueObjects\ReviewBody;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DemoCommunityStageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        config([
            'demo-data.user_count' => 4,
            'demo-data.coverage_numerator' => 1,
            'demo-data.coverage_denominator' => 2,
            'demo-data.chunk_size' => 100,
            'demo-data.asset_disk' => 'uploads',
            'demo-data.asset_prefix' => 'demo-tests',
            'demo-data.personal_tags.minimum' => 12,
            'demo-data.personal_tags.maximum' => 12,
            'demo-data.collections.minimum' => 8,
            'demo-data.collections.maximum' => 8,
            'demo-data.public_tag_target' => 12,
            'session.driver' => 'array',
        ]);
    }

    public function test_community_stage_creates_unique_reviews_dialogues_votes_and_reactions_idempotently(): void
    {
        CatalogTitle::factory()->count(24)->create()->each(function (CatalogTitle $title): void {
            $season = Season::factory()->create(['catalog_title_id' => $title->id, 'number' => 1]);
            $episode = Episode::factory()->create(['season_id' => $season->id, 'number' => 1]);
            LicensedMedia::factory()->create([
                'catalog_title_id' => $title->id,
                'season_id' => $season->id,
                'episode_id' => $episode->id,
                'status' => 'published',
                'published_at' => now()->subDay(),
                'duration_seconds' => 2_400,
            ]);
        });
        $options = DemoDataOptions::fromConfig();
        app(DemoAccountStage::class)->run($options);
        app(DemoOrganizationStage::class)->run($options);
        app(DemoCatalogActivityStage::class)->run($options);
        $stage = app(DemoCommunityStage::class);
        $first = $stage->run($options);
        $counts = $this->communityCounts();
        $second = $stage->run($options);

        $this->assertSame('community', $stage->key());
        $this->assertSame(48, $first->counters['reviews']);
        $this->assertSame(48, $first->counters['root_comments']);
        $this->assertSame($first->counters, $second->counters);
        $this->assertSame($counts, $this->communityCounts());

        $reviews = CatalogTitleReview::query()->where('origin', ReviewOrigin::User->value)->get();
        $rootComments = Comment::query()
            ->withTrashed()
            ->whereNull('parent_id')
            ->where('target_type', '!=', CommentTargetType::Collection->value)
            ->get();
        $collectionComments = Comment::query()
            ->withTrashed()
            ->whereNull('parent_id')
            ->where('target_type', CommentTargetType::Collection->value)
            ->get();

        $this->assertCount(48, $reviews);
        $this->assertCount(48, $rootComments);
        $this->assertCount(32, $collectionComments);
        $this->assertCount(48, $reviews->pluck('body')->unique());
        $this->assertCount(48, $rootComments->pluck('body')->unique());
        $this->assertCount(48, $reviews->pluck('ownership_key')->unique());
        $this->assertCount(48, $reviews->pluck('submission_key')->unique());
        $this->assertEqualsCanonicalizing(
            array_column(ReviewStatus::cases(), 'value'),
            $reviews->pluck('status')->map->value->unique()->values()->all(),
        );
        $this->assertEqualsCanonicalizing(
            array_column(CommentStatus::cases(), 'value'),
            $rootComments->pluck('status')->map->value->unique()->values()->all(),
        );

        foreach ($reviews as $review) {
            $this->assertNotSame('', ReviewBody::from($review->body)->normalizedHash);
        }

        foreach ($rootComments as $comment) {
            $this->assertNotSame('', CommentBody::from($comment->body)->hash);
        }

        $dialogueRoots = $rootComments->filter(
            fn (Comment $comment): bool => Comment::query()->withTrashed()->where('parent_id', $comment->id)->exists(),
        );
        $this->assertCount(12, $dialogueRoots);

        foreach ($dialogueRoots as $root) {
            $replies = Comment::query()->withTrashed()->where('parent_id', $root->id)->oldest('created_at')->get();
            $this->assertGreaterThanOrEqual(2, $replies->count());
            $this->assertLessThanOrEqual(8, $replies->count());
            $participants = $replies->pluck('user_id')->push($root->user_id)->unique();
            $this->assertGreaterThanOrEqual(2, $participants->count());
            $this->assertLessThanOrEqual(6, $participants->count());
            $previousId = $root->id;
            $previousCreatedAt = $root->created_at;

            foreach ($replies as $reply) {
                $this->assertSame($root->id, $reply->parent_id);
                $this->assertSame($previousId, $reply->reply_to_id);
                $this->assertTrue($reply->created_at->greaterThan($previousCreatedAt));
                $this->assertNotSame('', CommentBody::from($reply->body)->hash);
                $previousId = $reply->id;
                $previousCreatedAt = $reply->created_at;
            }
        }

        $selfReviewVotes = DB::table('catalog_title_review_votes')
            ->join('catalog_title_reviews', 'catalog_title_reviews.id', '=', 'catalog_title_review_votes.catalog_title_review_id')
            ->whereColumn('catalog_title_review_votes.user_id', 'catalog_title_reviews.user_id')
            ->count();
        $selfCommentReactions = DB::table('comment_reactions')
            ->join('comments', 'comments.id', '=', 'comment_reactions.comment_id')
            ->whereColumn('comment_reactions.user_id', 'comments.user_id')
            ->count();

        $this->assertSame(0, $selfReviewVotes);
        $this->assertSame(0, $selfCommentReactions);
        $this->assertFalse(DB::table('catalog_title_review_votes')
            ->select(['catalog_title_review_id', 'user_id'])
            ->groupBy(['catalog_title_review_id', 'user_id'])
            ->havingRaw('count(*) > 1')
            ->exists());
        $this->assertFalse(DB::table('comment_reactions')
            ->select(['comment_id', 'user_id'])
            ->groupBy(['comment_id', 'user_id'])
            ->havingRaw('count(*) > 1')
            ->exists());
    }

    /** @return array<string, int> */
    private function communityCounts(): array
    {
        return [
            'reviews' => DB::table('catalog_title_reviews')->where('origin', ReviewOrigin::User->value)->count(),
            'review_votes' => DB::table('catalog_title_review_votes')->count(),
            'comments' => DB::table('comments')->count(),
            'comment_reactions' => DB::table('comment_reactions')->count(),
        ];
    }
}
