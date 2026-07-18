<?php

declare(strict_types=1);

namespace Tests\Unit\DemoData;

use App\DTOs\DemoData\DemoDataOptions;
use App\Enums\PublicationStatus;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\DemoData\DemoBulkWriter;
use App\Services\DemoData\DemoRasterAsset;
use App\Services\DemoData\DemoStableValue;
use App\Services\DemoData\DemoTitleSelector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DemoTitleSelectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'demo-data.user_count' => 4,
            'demo-data.coverage_numerator' => 1,
            'demo-data.coverage_denominator' => 2,
            'demo-data.chunk_size' => 100,
            'demo-data.asset_disk' => 'uploads',
            'demo-data.asset_prefix' => 'demo-tests',
        ]);
    }

    public function test_each_user_receives_exactly_half_of_published_titles_deterministically(): void
    {
        $published = CatalogTitle::factory()->count(8)->create()->sortBy('id')->values();
        $unpublished = CatalogTitle::factory()->count(2)->create([
            'is_published' => false,
            'publication_status' => PublicationStatus::Draft,
        ]);
        $selector = new DemoTitleSelector(DemoDataOptions::fromConfig());
        $selections = collect(range(1, 4))->mapWithKeys(
            fn (int $userIndex): array => [$userIndex => $selector->selectedIds($userIndex)->all()],
        );

        foreach ($selections as $userIndex => $selectedIds) {
            $this->assertCount(4, $selectedIds);
            $this->assertSame($selectedIds, $selector->selectedIds($userIndex)->all());
            $this->assertEmpty(array_intersect($selectedIds, $unpublished->modelKeys()));
        }

        $frequency = $selections->flatten()->countBy()->sortKeys();

        $this->assertSame($published->modelKeys(), $frequency->keys()->all());
        $this->assertSame(array_fill(0, 8, 2), $frequency->values()->all());
        $this->assertSame(8, $selector->publishedCount());
    }

    public function test_contexts_are_projected_with_release_media_and_genre_data(): void
    {
        $title = CatalogTitle::factory()->create([
            'title' => 'Северный берег',
            'original_title' => 'Northern Shore',
            'year' => 2024,
        ]);
        $season = Season::factory()->create(['catalog_title_id' => $title->id, 'number' => 1]);
        $firstEpisode = Episode::factory()->create(['season_id' => $season->id, 'number' => 1]);
        $lastEpisode = Episode::factory()->create(['season_id' => $season->id, 'number' => 2]);
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $firstEpisode->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
            'duration_seconds' => 2_400,
        ]);
        $genres = collect(['Драма', 'Детектив'])->map(
            fn (string $name): Genre => Genre::query()->create(['name' => $name, 'slug' => str($name)->slug()]),
        );
        $title->genres()->sync($genres->pluck('id')->all());

        $context = (new DemoTitleSelector(DemoDataOptions::fromConfig()))
            ->contexts([$title->id])
            ->get($title->id);

        $this->assertNotNull($context);
        $this->assertSame($title->id, $context->titleId);
        $this->assertSame('Северный берег', $context->displayTitle);
        $this->assertSame($season->id, $context->firstSeasonId);
        $this->assertSame($firstEpisode->id, $context->firstEpisodeId);
        $this->assertSame($lastEpisode->id, $context->lastEpisodeId);
        $this->assertSame($media->id, $context->licensedMediaId);
        $this->assertSame(2_400, $context->durationSeconds);
        $this->assertEqualsCanonicalizing(['Драма', 'Детектив'], $context->genreNames);
    }

    public function test_raster_assets_are_private_stable_valid_png_files(): void
    {
        Storage::fake('uploads');
        $asset = new DemoRasterAsset(DemoDataOptions::fromConfig(), new DemoStableValue('seasonvar-demo-v1'));

        $first = $asset->store('avatars', 'user:1', 320, 320);
        $second = $asset->store('avatars', 'user:1', 320, 320);
        $bytes = Storage::disk('uploads')->get($first['path']);
        $image = getimagesizefromstring($bytes);

        $this->assertSame($first, $second);
        Storage::disk('uploads')->assertExists($first['path']);
        $this->assertSame('image/png', $first['mime_type']);
        $this->assertSame(hash('sha256', $bytes), $first['hash']);
        $this->assertSame([320, 320], [$image[0], $image[1]]);
        $this->assertSame('private', Storage::disk('uploads')->visibility($first['path']));
    }

    public function test_bulk_writer_upserts_existing_rows_and_accepts_empty_batches(): void
    {
        $user = User::factory()->create(['name' => 'Старое имя']);
        $writer = new DemoBulkWriter(DemoDataOptions::fromConfig());

        $this->assertSame(0, $writer->upsert($user->getTable(), [], ['email'], ['name']));
        $this->assertSame(1, $writer->upsert($user->getTable(), [[
            'email' => $user->email,
            'name' => 'Новое имя',
            'password' => $user->password,
            'email_verified_at' => $user->email_verified_at,
            'created_at' => $user->created_at,
            'updated_at' => now(),
        ]], ['email'], ['name', 'updated_at']));

        $this->assertSame('Новое имя', $user->fresh()->name);
    }

    public function test_bulk_writer_limits_each_statement_to_a_memory_safe_row_count(): void
    {
        config()->set('demo-data.chunk_size', 1_000);
        DB::statement('CREATE TEMP TABLE demo_bulk_rows (id INTEGER PRIMARY KEY, payload TEXT NOT NULL)');
        $bindingCounts = [];
        DB::listen(function (QueryExecuted $query) use (&$bindingCounts): void {
            if (str_contains($query->sql, 'demo_bulk_rows') && str_starts_with($query->sql, 'insert')) {
                $bindingCounts[] = count($query->bindings);
            }
        });
        $rows = collect(range(1, 250))
            ->map(fn (int $id): array => ['id' => $id, 'payload' => str_repeat('x', 100)])
            ->all();

        $affected = (new DemoBulkWriter(DemoDataOptions::fromConfig()))
            ->upsert('demo_bulk_rows', $rows, ['id'], ['payload']);

        $this->assertSame(250, $affected);
        $this->assertCount(3, $bindingCounts);
        $this->assertLessThanOrEqual(200, max($bindingCounts));
        $this->assertSame(250, DB::table('demo_bulk_rows')->count());
    }
}
