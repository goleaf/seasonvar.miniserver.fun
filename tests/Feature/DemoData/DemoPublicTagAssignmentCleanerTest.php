<?php

declare(strict_types=1);

namespace Tests\Feature\DemoData;

use App\DTOs\DemoData\DemoDataOptions;
use App\Enums\TagModerationStatus;
use App\Enums\TagSource;
use App\Enums\TagType;
use App\Enums\TagVisibility;
use App\Models\CatalogRecommendationBuild;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\CatalogTitleTagSource;
use App\Models\SeasonvarImportRun;
use App\Models\Tag;
use App\Services\DemoData\DemoPublicTagAssignmentCleaner;
use App\Services\DemoData\DemoStableValue;
use App\Services\DemoData\DemoTitleSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class DemoPublicTagAssignmentCleanerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'demo-data.version' => 'seasonvar-demo-v1',
            'demo-data.user_count' => 4,
            'demo-data.coverage_numerator' => 1,
            'demo-data.coverage_denominator' => 2,
            'demo-data.chunk_size' => 100,
            'demo-data.public_tag_target' => 12,
            'cache-architecture.warming.enabled' => false,
        ]);
    }

    public function test_it_removes_only_the_historical_demo_footprint_without_current_provenance(): void
    {
        $fixture = $this->historicalFixture(withOwnedDemoTag: true);
        $affectedTitleId = $fixture['selected_title_ids'][0];
        $protected = $fixture['expected_pairs'][0];
        $unrelatedTag = Tag::query()->create([
            'name' => 'Подтверждение вне demo footprint',
            'slug' => 'outside-demo-footprint',
            'type' => TagType::Editorial,
            'visibility' => TagVisibility::Public,
            'moderation_status' => TagModerationStatus::Approved,
            'source' => TagSource::Editorial,
        ]);
        DB::table('catalog_title_tag')->insert([
            'catalog_title_id' => $affectedTitleId,
            'tag_id' => $unrelatedTag->id,
        ]);
        CatalogTitleTagSource::query()->create([
            'catalog_title_id' => $protected['catalog_title_id'],
            'tag_id' => $protected['tag_id'],
            'source' => TagSource::Seasonvar,
            'provider' => 'seasonvar',
            'source_id' => null,
            'source_key' => hash('sha256', 'provider-protected'),
            'is_current' => true,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        DB::table('catalog_title_tag')->insertOrIgnore([
            'catalog_title_id' => $fixture['outside_title_id'],
            'tag_id' => $fixture['owned_demo_tag_id'],
        ]);
        CatalogTitleRecommendation::query()->create([
            'catalog_title_id' => $affectedTitleId,
            'recommended_title_id' => $fixture['outside_title_id'],
            'score' => 100,
            'rank' => 1,
            'computed_at' => now(),
        ]);

        $cleaner = app(DemoPublicTagAssignmentCleaner::class);
        $before = $cleaner->inspect(DemoDataOptions::fromConfig());
        $result = $cleaner->repair(DemoDataOptions::fromConfig());

        $this->assertSame(count($fixture['expected_pairs']), $before['expected_pairs']);
        $this->assertGreaterThan(0, $before['cleanup_candidates']);
        $this->assertSame(1, $before['owned_demo_tags']);
        $this->assertGreaterThan(0, $result['removed_assignments']);
        $this->assertSame(1, $result['archived_demo_tags']);
        $this->assertDatabaseHas('catalog_title_tag', $protected);
        $this->assertDatabaseHas('catalog_title_tag', [
            'catalog_title_id' => $affectedTitleId,
            'tag_id' => $unrelatedTag->id,
        ]);

        foreach ($fixture['expected_pairs'] as $pair) {
            if ($pair === $protected) {
                continue;
            }

            $this->assertDatabaseMissing('catalog_title_tag', $pair);
        }

        $this->assertDatabaseMissing('catalog_title_tag', [
            'catalog_title_id' => $fixture['outside_title_id'],
            'tag_id' => $fixture['owned_demo_tag_id'],
        ]);
        $this->assertDatabaseHas('tags', [
            'id' => $fixture['owned_demo_tag_id'],
            'visibility' => TagVisibility::Internal->value,
            'moderation_status' => TagModerationStatus::Archived->value,
            'archived_from_visibility' => TagVisibility::Public->value,
            'archived_from_moderation_status' => TagModerationStatus::Approved->value,
        ]);
        $this->assertDatabaseMissing('catalog_title_recommendations', [
            'catalog_title_id' => $affectedTitleId,
        ]);
        $this->assertDatabaseHas('catalog_recommendation_dirty_titles', [
            'catalog_title_id' => $affectedTitleId,
            'reason' => 'demo-tag-quality-repair',
        ]);

        $second = $cleaner->repair(DemoDataOptions::fromConfig());

        $this->assertSame(0, $second['removed_assignments']);
        $this->assertSame(0, $second['archived_demo_tags']);
    }

    public function test_it_fails_closed_when_the_owned_demo_tag_fingerprint_is_absent(): void
    {
        $fixture = $this->historicalFixture(withOwnedDemoTag: false);
        $versionHash = substr(hash('sha256', 'seasonvar-demo-v1'), 0, 12);
        Tag::query()->create([
            'name' => 'Не принадлежащий demo тег',
            'slug' => 'not-owned-demo-tag',
            'code' => "demo-tag-{$versionHash}-1",
            'type' => TagType::System,
            'visibility' => TagVisibility::Public,
            'moderation_status' => TagModerationStatus::Approved,
            'source' => TagSource::System,
        ]);

        try {
            app(DemoPublicTagAssignmentCleaner::class)->repair(DemoDataOptions::fromConfig());
            $this->fail('Cleanup should require an exact owned demo-tag fingerprint.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Не найден exact fingerprint прежнего demo public-tag набора; очистка остановлена.',
                $exception->getMessage(),
            );
        }

        foreach ($fixture['expected_pairs'] as $pair) {
            $this->assertDatabaseHas('catalog_title_tag', $pair);
        }
    }

    public function test_it_refuses_to_write_while_a_seasonvar_import_is_active(): void
    {
        $fixture = $this->historicalFixture(withOwnedDemoTag: true);
        SeasonvarImportRun::query()->create([
            'mode' => 'all',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Очистка demo-тегов запрещена во время активного импорта Seasonvar.');

        try {
            app(DemoPublicTagAssignmentCleaner::class)->repair(DemoDataOptions::fromConfig());
        } finally {
            foreach ($fixture['expected_pairs'] as $pair) {
                $this->assertDatabaseHas('catalog_title_tag', $pair);
            }
        }
    }

    public function test_it_refuses_to_write_while_a_recommendation_build_is_unfinished(): void
    {
        $fixture = $this->historicalFixture(withOwnedDemoTag: true);
        CatalogRecommendationBuild::query()->create([
            'algorithm_version' => 'test',
            'feature_version' => 'test',
            'status' => 'building',
            'started_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Очистка demo-тегов запрещена при незавершённой сборке рекомендаций.');

        try {
            app(DemoPublicTagAssignmentCleaner::class)->repair(DemoDataOptions::fromConfig());
        } finally {
            foreach ($fixture['expected_pairs'] as $pair) {
                $this->assertDatabaseHas('catalog_title_tag', $pair);
            }
        }
    }

    public function test_it_fails_closed_when_the_historical_match_is_too_sparse(): void
    {
        $fixture = $this->historicalFixture(withOwnedDemoTag: true);
        $retainedPair = $fixture['expected_pairs'][0];

        DB::table('catalog_title_tag')
            ->whereNot(function ($query) use ($retainedPair): void {
                $query
                    ->where('catalog_title_id', $retainedPair['catalog_title_id'])
                    ->where('tag_id', $retainedPair['tag_id']);
            })
            ->delete();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Historical demo public-tag footprint не подтверждён с достаточной точностью; очистка остановлена.',
        );

        try {
            app(DemoPublicTagAssignmentCleaner::class)->repair(DemoDataOptions::fromConfig());
        } finally {
            $this->assertDatabaseHas('catalog_title_tag', $retainedPair);
            $this->assertDatabaseHas('tags', [
                'id' => $fixture['owned_demo_tag_id'],
                'visibility' => TagVisibility::Public->value,
                'moderation_status' => TagModerationStatus::Approved->value,
            ]);
        }
    }

    /**
     * @return array{
     *     expected_pairs: list<array{catalog_title_id: int, tag_id: int}>,
     *     selected_title_ids: list<int>,
     *     outside_title_id: int,
     *     owned_demo_tag_id: int|null
     * }
     */
    private function historicalFixture(bool $withOwnedDemoTag): array
    {
        $titles = CatalogTitle::factory()->count(4)->create()->sortBy('id')->values();
        $versionHash = substr(hash('sha256', 'seasonvar-demo-v1'), 0, 12);
        $stable = new DemoStableValue('seasonvar-demo-v1');
        $ownedDemoTagId = null;

        for ($ordinal = 0; $ordinal < 12; $ordinal++) {
            $isOwnedDemo = $withOwnedDemoTag && $ordinal === 11;
            $tag = Tag::query()->create([
                ...($isOwnedDemo ? [
                    'public_id' => $stable->uuid('organization:public-tag:0'),
                ] : []),
                'name' => 'Тестовый глобальный тег '.($ordinal + 1),
                'slug' => $isOwnedDemo
                    ? 'normalized-owned-demo-tag'
                    : 'global-tag-'.($ordinal + 1),
                'code' => $isOwnedDemo ? "demo-tag-{$versionHash}-1" : null,
                'type' => $isOwnedDemo ? TagType::System : TagType::Imported,
                'visibility' => TagVisibility::Public,
                'moderation_status' => TagModerationStatus::Approved,
                'source' => $isOwnedDemo ? TagSource::System : TagSource::Seasonvar,
            ]);

            if ($isOwnedDemo) {
                $ownedDemoTagId = (int) $tag->id;
            }
        }

        $options = DemoDataOptions::fromConfig();
        $tagIds = Tag::query()->orderBy('id')->limit($options->publicTagTarget)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $selectedTitleIds = (new DemoTitleSelector($options))->selectedIds(1)->all();
        $pairs = [];

        foreach ($selectedTitleIds as $titleId) {
            $count = min(count($tagIds), $stable->integer(
                "organization:title:{$titleId}:public-tag-count",
                min(3, count($tagIds)),
                min(12, count($tagIds)),
            ));
            $offset = $stable->integer(
                "organization:title:{$titleId}:public-tag-offset",
                0,
                count($tagIds) - 1,
            );

            for ($ordinal = 0; $ordinal < $count; $ordinal++) {
                $pairs[] = [
                    'catalog_title_id' => $titleId,
                    'tag_id' => $tagIds[($offset + $ordinal) % count($tagIds)],
                ];
            }
        }

        DB::table('catalog_title_tag')->insertOrIgnore($pairs);

        return [
            'expected_pairs' => $pairs,
            'selected_title_ids' => $selectedTitleIds,
            'outside_title_id' => (int) $titles->last()->id,
            'owned_demo_tag_id' => $ownedDemoTagId,
        ];
    }
}
