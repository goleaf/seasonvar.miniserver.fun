<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogCollection;
use App\Models\CatalogCollectionSource;
use App\Services\Collections\CatalogCollectionCoverPurgeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class PurgeCatalogCollectionCoversCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_mode_reports_exact_targets_without_mutating_storage_or_database(): void
    {
        Storage::fake('uploads');
        [$collection, $source] = $this->legacyCoverRecords();
        Storage::disk('uploads')->put('catalog-collections/private-id/legacy.webp', 'cover');
        Storage::disk('uploads')->put('profile-covers/keep.webp', 'profile');

        $this->assertSame('catalog-collections/', CatalogCollectionCoverPurgeService::PREFIX);

        $this->artisan('catalog-collections:purge-covers')
            ->expectsOutputToContain('Режим: dry-run')
            ->expectsOutputToContain('Файлов: 1')
            ->expectsOutputToContain('Байт: 5')
            ->assertSuccessful();

        Storage::disk('uploads')->assertExists('catalog-collections/private-id/legacy.webp');
        Storage::disk('uploads')->assertExists('profile-covers/keep.webp');
        $this->assertSame('catalog-collections/private-id/legacy.webp', $collection->fresh()?->getRawOriginal('cover_path'));
        $this->assertSame('/remote/private-cover.webp', $source->fresh()?->getRawOriginal('cover_source_path'));
    }

    public function test_execute_deletes_only_the_fixed_prefix_clears_metadata_and_is_idempotent(): void
    {
        Storage::fake('uploads');
        [$collection, $source] = $this->legacyCoverRecords();
        Storage::disk('uploads')->put('catalog-collections/private-id/first.webp', 'first');
        Storage::disk('uploads')->put('catalog-collections/private-id/nested/second.webp', 'second');
        Storage::disk('uploads')->put('posters/keep.webp', 'poster');
        Storage::disk('uploads')->put('profile-covers/keep.webp', 'profile');

        $this->artisan('catalog-collections:purge-covers', ['--execute' => true])
            ->expectsOutputToContain('Режим: execute')
            ->expectsOutputToContain('Готовность к удалению колонок: да')
            ->assertSuccessful();

        Storage::disk('uploads')->assertMissing('catalog-collections');
        Storage::disk('uploads')->assertExists('posters/keep.webp');
        Storage::disk('uploads')->assertExists('profile-covers/keep.webp');
        $this->assertNull($collection->fresh()?->getRawOriginal('cover_disk'));
        $this->assertNull($collection->fresh()?->getRawOriginal('cover_path'));
        $this->assertNull($collection->fresh()?->getRawOriginal('cover_mime_type'));
        $this->assertNull($collection->fresh()?->getRawOriginal('cover_size'));
        $this->assertSame(0, $collection->fresh()?->getRawOriginal('cover_version'));
        $this->assertNull($source->fresh()?->getRawOriginal('cover_source_path'));
        $this->assertNull($source->fresh()?->getRawOriginal('cover_path'));
        $this->assertNull($source->fresh()?->getRawOriginal('cover_content_hash'));

        $this->artisan('catalog-collections:purge-covers', ['--execute' => true])
            ->expectsOutputToContain('Файлов: 0')
            ->expectsOutputToContain('Строк подборок: 0')
            ->expectsOutputToContain('Строк источников: 0')
            ->assertSuccessful();
    }

    public function test_storage_failure_returns_non_zero_keeps_metadata_and_does_not_disclose_paths(): void
    {
        [$collection] = $this->legacyCoverRecords();
        $privatePath = 'catalog-collections/private-id/do-not-print.webp';
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('allFiles')
            ->with(CatalogCollectionCoverPurgeService::PREFIX)
            ->andReturn([$privatePath]);
        $disk->shouldReceive('size')->with($privatePath)->andReturn(12);
        $disk->shouldReceive('delete')->once()->with([$privatePath])->andReturnFalse();
        $manager = Mockery::mock(FilesystemManager::class);
        $manager->shouldReceive('disk')->with('uploads')->andReturn($disk);
        $this->app->instance(FilesystemManager::class, $manager);

        $exitCode = Artisan::call('catalog-collections:purge-covers', ['--execute' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Готовность к удалению колонок: нет', $output);
        $this->assertStringNotContainsString($privatePath, $output);
        $this->assertSame('catalog-collections/private-id/legacy.webp', $collection->fresh()?->getRawOriginal('cover_path'));
    }

    /** @return array{CatalogCollection, CatalogCollectionSource} */
    private function legacyCoverRecords(): array
    {
        $this->restoreLegacyColumns();

        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => 'Legacy cover fixture',
            'slug' => 'legacy-cover-'.Str::lower(Str::random(10)),
            'visibility' => 'private',
            'moderation_status' => 'approved',
            'sort_mode' => 'manual',
            'content_version' => 1,
        ]);
        DB::table('catalog_collections')->where('id', $collection->id)->update([
            'cover_disk' => 'uploads',
            'cover_path' => 'catalog-collections/private-id/legacy.webp',
            'cover_mime_type' => 'image/webp',
            'cover_size' => 5,
            'cover_version' => 7,
        ]);
        $source = CatalogCollectionSource::query()->create([
            'provider' => 'fixture',
            'source_key' => hash('sha256', (string) Str::uuid()),
            'catalog_collection_id' => $collection->id,
            'source_path' => '/collections/fixture/',
            'remote_name' => 'Legacy source',
        ]);
        DB::table('catalog_collection_sources')->where('id', $source->id)->update([
            'cover_source_path' => '/remote/private-cover.webp',
            'cover_path' => 'catalog-collections/private-id/legacy.webp',
            'cover_content_hash' => hash('sha256', 'cover'),
        ]);

        return [$collection->fresh(), $source->fresh()];
    }

    private function restoreLegacyColumns(): void
    {
        if (! Schema::hasColumn('catalog_collections', 'cover_disk')) {
            Schema::table('catalog_collections', static function (Blueprint $table): void {
                $table->string('cover_disk', 64)->nullable();
                $table->string('cover_path', 512)->nullable();
                $table->string('cover_mime_type', 96)->nullable();
                $table->unsignedBigInteger('cover_size')->nullable();
                $table->unsignedBigInteger('cover_version')->default(0);
            });
        }

        if (! Schema::hasColumn('catalog_collection_sources', 'cover_source_path')) {
            Schema::table('catalog_collection_sources', static function (Blueprint $table): void {
                $table->string('cover_source_path', 512)->nullable();
                $table->string('cover_path', 512)->nullable();
                $table->char('cover_content_hash', 64)->nullable();
            });
        }
    }
}
