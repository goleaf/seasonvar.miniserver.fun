<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CommentTargetType;
use App\Enums\ContentRequestType;
use App\Enums\ReleaseScheduleEntryType;
use App\Enums\TechnicalIssueTargetType;
use App\Enums\TechnicalIssueType;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\Catalog\CatalogTitleUserDataMerger;
use App\Services\Comments\CommentTargetMergeService;
use App\Services\ContentRequests\ContentRequestTargetMergeService;
use App\Services\ReleaseCalendar\ReleaseCalendarTargetMergeService;
use App\Services\TechnicalIssues\TechnicalIssueTargetMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SeasonvarMergeDependencyPresenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_merge_dependency_prefetches_return_only_occupied_targets(): void
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->create(['catalog_title_id' => $title->id]);
        $occupiedEpisode = Episode::factory()->create(['season_id' => $season->id, 'number' => 1]);
        $emptyEpisode = Episode::factory()->create(['season_id' => $season->id, 'number' => 2]);
        $occupiedMedia = LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $occupiedEpisode->id,
        ]);
        $emptyMedia = LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $emptyEpisode->id,
        ]);
        $user = User::factory()->create();
        $timestamp = now();

        DB::table('comments')->insert([
            'target_type' => CommentTargetType::Episode->value,
            'target_id' => $occupiedEpisode->id,
            'catalog_title_id' => $title->id,
            'body' => 'Проверка зависимости merge',
            'body_hash' => hash('sha256', 'Проверка зависимости merge'),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('content_requests')->insert([
            'public_id' => (string) Str::uuid(),
            'type' => ContentRequestType::Episode->value,
            'title' => 'Проверка зависимости merge',
            'normalized_title' => 'проверка зависимости merge',
            'normalized_title_hash' => hash('sha256', 'проверка зависимости merge'),
            'episode_id' => $occupiedEpisode->id,
            'exact_identity_hash' => hash('sha256', 'merge-request'),
            'submission_key' => hash('sha256', 'merge-request-submission'),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('technical_issues')->insert([
            'public_id' => (string) Str::uuid(),
            'public_number' => 'MERGE-1',
            'type' => TechnicalIssueType::WrongEpisode->value,
            'target_type' => TechnicalIssueTargetType::Episode->value,
            'target_label_snapshot' => 'Проверка зависимости merge',
            'episode_id' => $occupiedEpisode->id,
            'licensed_media_id' => $occupiedMedia->id,
            'locale' => 'ru',
            'exact_identity_hash' => hash('sha256', 'merge-issue'),
            'submission_key' => hash('sha256', 'merge-issue-submission'),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('release_schedule_entries')->insert([
            'public_id' => (string) Str::uuid(),
            'logical_key' => 'merge-dependency-profile',
            'entry_type' => ReleaseScheduleEntryType::EpisodeRelease->value,
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $occupiedEpisode->id,
            'licensed_media_id' => $occupiedMedia->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('episode_view_progress')->insert([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'episode_id' => $occupiedEpisode->id,
            'last_watched_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $episodeIds = [$occupiedEpisode->id, $emptyEpisode->id];
        $mediaIds = [$occupiedMedia->id, $emptyMedia->id];

        $this->assertSame(
            [$occupiedEpisode->id => true],
            app(CommentTargetMergeService::class)->episodeIdsWithComments($episodeIds),
        );
        $this->assertSame(
            [$occupiedEpisode->id => true],
            app(ContentRequestTargetMergeService::class)->episodeIdsWithRequests($episodeIds),
        );
        $this->assertSame(
            [$occupiedEpisode->id => true],
            app(TechnicalIssueTargetMergeService::class)->episodeIdsWithIssues($episodeIds),
        );
        $this->assertSame(
            [$occupiedMedia->id => true],
            app(TechnicalIssueTargetMergeService::class)->mediaIdsWithIssues($mediaIds),
        );
        $this->assertSame(
            [$occupiedEpisode->id => true],
            app(ReleaseCalendarTargetMergeService::class)->episodeIdsWithEntries($episodeIds),
        );
        $this->assertSame(
            [$occupiedMedia->id => true],
            app(ReleaseCalendarTargetMergeService::class)->mediaIdsWithEntries($mediaIds),
        );
        $this->assertSame(
            [$occupiedEpisode->id => true],
            app(CatalogTitleUserDataMerger::class)->episodeIdsWithUserData($episodeIds),
        );
    }
}
