<?php

declare(strict_types=1);

namespace Tests\Feature\DemoData;

use App\DTOs\DemoData\DemoDataOptions;
use App\Jobs\WarmUserPortalCache;
use App\Models\CatalogCollection;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\ContentRequest;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\UserTag;
use App\Services\DemoData\Stages\DemoAccountStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DemoUserPortalRepairCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        config([
            'uploads.disk' => 'uploads',
            'demo-data.user_count' => 2,
            'demo-data.coverage_numerator' => 1,
            'demo-data.coverage_denominator' => 2,
            'demo-data.chunk_size' => 100,
            'demo-data.minimum_free_bytes' => 0,
            'demo-data.asset_disk' => 'uploads',
            'demo-data.personal_tags.minimum' => 2,
            'demo-data.personal_tags.maximum' => 2,
            'demo-data.personal_tags.per_title_minimum' => 1,
            'demo-data.personal_tags.per_title_maximum' => 1,
            'demo-data.collections.minimum' => 2,
            'demo-data.collections.maximum' => 2,
            'demo-data.collections.per_title_minimum' => 1,
            'demo-data.collections.per_title_maximum' => 1,
            'demo-data.requests.minimum' => 2,
            'demo-data.requests.maximum' => 2,
            'demo-data.public_tag_target' => 2,
            'session.driver' => 'array',
            'cache-architecture.warming.user_portal_enabled' => true,
        ]);

        CatalogTitle::factory()->count(4)->create()->each(function (CatalogTitle $title): void {
            $season = Season::factory()->create(['catalog_title_id' => $title->id, 'number' => 1]);
            $episode = Episode::factory()->create(['season_id' => $season->id, 'number' => 1]);
            LicensedMedia::factory()->create([
                'catalog_title_id' => $title->id,
                'season_id' => $season->id,
                'episode_id' => $episode->id,
                'status' => 'published',
                'published_at' => now()->subDay(),
            ]);
        });

        app(DemoAccountStage::class)->run(DemoDataOptions::fromConfig());
    }

    public function test_dry_run_does_not_write_and_force_repairs_then_queues_each_demo_owner(): void
    {
        Queue::fake();

        $this->artisan('demo:repair-user-portal', ['--dry-run' => true])
            ->expectsOutputToContain('Dry-run завершён')
            ->expectsOutputToContain('users_without_requests: 2')
            ->assertSuccessful();

        $this->assertSame(0, ContentRequest::query()->count());
        $this->assertSame(0, UserTag::query()->count());
        $this->assertSame(0, CatalogCollection::query()->count());
        $this->assertSame(0, CatalogTitleUserState::query()->count());

        $this->artisan('demo:repair-user-portal', ['--force' => true])
            ->expectsOutputToContain('Ограниченный repair завершён')
            ->expectsOutputToContain('users_without_requests: 0')
            ->assertSuccessful();

        $this->assertSame(4, ContentRequest::query()->count());
        $this->assertSame(4, UserTag::query()->count());
        $this->assertSame(4, CatalogCollection::query()->count());
        $this->assertSame(4, CatalogTitleUserState::query()->count());
        Queue::assertPushed(WarmUserPortalCache::class, 2);
    }

    public function test_production_write_requires_backup_and_writer_pause_confirmations(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->artisan('demo:repair-user-portal', ['--force' => true])
            ->expectsOutputToContain('Production repair требует')
            ->assertFailed();

        $this->assertSame(0, ContentRequest::query()->count());
        $this->assertSame(0, UserTag::query()->count());
        $this->assertSame(0, CatalogTitleUserState::query()->count());
    }
}
