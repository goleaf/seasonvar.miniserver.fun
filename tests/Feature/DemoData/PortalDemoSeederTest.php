<?php

declare(strict_types=1);

namespace Tests\Feature\DemoData;

use App\Enums\ReviewOrigin;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleReview;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\Translation;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\DemoData\DemoDataOrchestrator;
use Database\Seeders\PortalDemoSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

final class PortalDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private const string TEST_DISK = 'portal-demo-seeder-uploads';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(self::TEST_DISK);
        Mail::fake();
        Http::preventStrayRequests();
        config([
            'demo-data.user_count' => 10,
            'demo-data.coverage_numerator' => 1,
            'demo-data.coverage_denominator' => 2,
            'demo-data.chunk_size' => 100,
            'demo-data.minimum_free_bytes' => 0,
            'demo-data.asset_disk' => self::TEST_DISK,
            'demo-data.asset_prefix' => 'demo-tests',
            'demo-data.personal_tags.minimum' => 12,
            'demo-data.personal_tags.maximum' => 12,
            'demo-data.collections.minimum' => 8,
            'demo-data.collections.maximum' => 8,
            'demo-data.public_tag_target' => 12,
            'demo-data.requests.minimum' => 10,
            'demo-data.requests.maximum' => 10,
            'demo-data.issues.minimum' => 6,
            'demo-data.issues.maximum' => 6,
            'demo-data.notifications.minimum' => 20,
            'demo-data.notifications.maximum' => 20,
            'session.driver' => 'array',
        ]);
    }

    public function test_full_demo_seed_runs_every_stage_and_is_idempotent(): void
    {
        $titles = $this->createCatalogFixtures();
        $providerReview = CatalogTitleReview::query()->create([
            'catalog_title_id' => $titles->firstOrFail()->id,
            'author' => 'Источник каталога',
            'body' => 'Исходная рецензия поставщика должна сохраниться без изменений.',
            'body_hash' => hash('sha256', 'Исходная рецензия поставщика должна сохраниться без изменений.'),
            'published_at' => now()->subYear(),
        ]);
        $stageKeys = [];
        $orchestrator = app(DemoDataOrchestrator::class);
        $first = $orchestrator->run(function (string $stage) use (&$stageKeys): void {
            $stageKeys[] = $stage;
        });
        $counts = $this->demoCounts();

        $this->seed(PortalDemoSeeder::class);
        $second = $orchestrator->run();

        $this->assertTrue($first->passed(), implode("\n", $first->violations));
        $this->assertTrue($second->passed(), implode("\n", $second->violations));
        $this->assertSame([], $first->violations);
        $this->assertSame($first->counters, $second->counters);
        $this->assertSame($counts, $this->demoCounts());
        $this->assertEqualsCanonicalizing([
            'accounts',
            'organization',
            'catalog_activity',
            'community',
            'content_requests',
            'moderation',
            'technical_issues',
            'notifications_sync',
        ], array_values(array_unique($stageKeys)));
        $this->assertSame(10, $first->counters['demo_users']);
        $this->assertSame(12, $first->counters['selected_titles_per_user']);
        $this->assertSame(120, $first->counters['user_title_states']);
        $this->assertSame(120, $first->counters['user_reviews']);
        $this->assertSame(120, $first->counters['root_title_comments']);
        $this->assertSame(100, $first->counters['content_requests']);
        $this->assertSame(120, $first->counters['personal_tags']);
        $this->assertSame(80, $first->counters['collections']);
        $this->assertSame(200, $first->counters['notifications']);
        $this->assertSame(24, CatalogTitle::query()->count());
        $this->assertSame(ReviewOrigin::Provider, $providerReview->fresh()?->origin);
        $this->assertSame(
            'Исходная рецензия поставщика должна сохраниться без изменений.',
            $providerReview->fresh()?->body,
        );

        $profile = UserProfile::query()->firstOrFail();
        $this->assertSame('image/webp', $profile->avatar_mime_type);
        $this->assertSame('image/webp', $profile->cover_mime_type);
        Storage::disk(self::TEST_DISK)->assertExists((string) $profile->avatar_path);
        Storage::disk(self::TEST_DISK)->assertExists((string) $profile->cover_path);
        Mail::assertNothingSent();
    }

    public function test_production_guard_runs_before_the_first_write(): void
    {
        CatalogTitle::factory()->create();
        $this->app->detectEnvironment(static fn (): string => 'production');

        try {
            app(DemoDataOrchestrator::class)->run();
            $this->fail('Production environment must reject the full demo seed.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('dev и testing', $exception->getMessage());
        }

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, DB::table('catalog_title_user_states')->count());
    }

    /** @return Collection<int, CatalogTitle> */
    private function createCatalogFixtures(): Collection
    {
        $translation = Translation::query()->create([
            'name' => 'Русская озвучка',
            'slug' => 'russian-voice',
        ]);

        return CatalogTitle::factory()->count(24)->create()->each(function (CatalogTitle $title) use ($translation): void {
            $title->translations()->attach($translation->id);
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
    }

    /** @return array<string, int> */
    private function demoCounts(): array
    {
        return collect([
            'users',
            'user_profiles',
            'catalog_title_user_states',
            'catalog_title_reviews',
            'comments',
            'content_requests',
            'technical_issues',
            'notifications',
            'api_sync_mutations',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
