<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogWatchStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\Genre;
use App\Models\User;
use App\Services\Catalog\CatalogPersonalNegativePreferenceBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogPersonalNegativePreferenceBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_feature_requires_three_independent_negative_titles(): void
    {
        $genre = Genre::query()->create(['name' => 'Криминал', 'slug' => 'crime-negative-threshold']);
        $two = User::factory()->create();
        $three = User::factory()->create();
        $this->negativeTitles($two, $genre, 2, now());
        $this->negativeTitles($three, $genre, 3, now());
        $builder = app(CatalogPersonalNegativePreferenceBuilder::class);

        $this->assertArrayNotHasKey("genre:{$genre->id}", $builder->forUser($two));
        $this->assertSame(90, $builder->forUser($three)["genre:{$genre->id}"]);
    }

    public function test_old_negative_evidence_is_weaker_and_positive_support_only_reduces_it(): void
    {
        $genre = Genre::query()->create(['name' => 'Детектив', 'slug' => 'detective-negative-decay']);
        $recent = User::factory()->create();
        $old = User::factory()->create();
        $this->negativeTitles($recent, $genre, 3, now());
        $this->negativeTitles($old, $genre, 3, now()->subYear());
        $positive = CatalogTitle::factory()->create();
        $positive->genres()->attach($genre);
        $builder = app(CatalogPersonalNegativePreferenceBuilder::class);
        $recentDemotion = $builder->forUser($recent)["genre:{$genre->id}"];
        $oldDemotion = $builder->forUser($old)["genre:{$genre->id}"];
        $reduced = $builder->forUser($recent, [$positive->id])["genre:{$genre->id}"];

        $this->assertGreaterThan($oldDemotion, $recentDemotion);
        $this->assertGreaterThan(0, $reduced);
        $this->assertLessThan($recentDemotion, $reduced);
        $this->assertLessThanOrEqual(120, $recentDemotion);
    }

    private function negativeTitles(User $user, Genre $genre, int $count, mixed $activity): void
    {
        CatalogTitle::factory()->count($count)->create()->each(function (CatalogTitle $title) use ($activity, $genre, $user): void {
            $title->genres()->attach($genre);
            CatalogTitleUserState::query()->create([
                'user_id' => $user->id,
                'catalog_title_id' => $title->id,
                'watch_status' => CatalogWatchStatus::Dropped,
                'watch_status_updated_at' => $activity,
            ]);
        });
    }
}
