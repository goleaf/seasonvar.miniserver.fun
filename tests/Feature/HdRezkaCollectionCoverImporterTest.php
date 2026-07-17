<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Services\Collections\Import\HdRezkaCollectionCoverImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class HdRezkaCollectionCoverImporterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        Http::preventStrayRequests();
        config([
            'uploads.disk' => 'uploads',
            'uploads.visibility' => 'private',
            'uploads.runtime_group' => '',
            'catalog-collection-imports.hdrezka.delay_seconds' => 0,
            'catalog-collection-imports.hdrezka.cover.max_source_bytes' => 1_000_000,
            'catalog-collection-imports.hdrezka.cover.max_source_dimension' => 4000,
            'catalog-collection-imports.hdrezka.cover.max_source_pixels' => 8_000_000,
            'catalog-collection-imports.hdrezka.cover.max_width' => 320,
            'catalog-collection-imports.hdrezka.cover.max_height' => 180,
            'catalog-collection-imports.hdrezka.cover.quality' => 82,
        ]);
    }

    public function test_prepare_converts_and_resizes_a_remote_cover_to_webp(): void
    {
        $sourceBytes = $this->imageBytes(800, 600, 'png', [30, 80, 180]);
        $url = 'https://hdrezka.my/uploads/mini/14/aa/cover.png';
        Http::fake([
            $url => Http::response($sourceBytes, 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => (string) strlen($sourceBytes),
            ]),
        ]);

        $cover = app(HdRezkaCollectionCoverImporter::class)->prepare($url);

        $this->assertNotNull($cover);
        $this->assertSame('image/webp', $cover->mimeType);
        $this->assertSame(240, $cover->width);
        $this->assertSame(180, $cover->height);
        $this->assertSame(strlen($cover->bytes), $cover->size);
        $this->assertSame(hash('sha256', $cover->bytes), $cover->contentHash);
        $this->assertSame('image/webp', getimagesizefromstring($cover->bytes)['mime'] ?? null);
        Http::assertSent(fn (Request $request): bool => $request->toPsrRequest()->getProtocolVersion() === '2.0');
    }

    public function test_apply_stores_a_private_content_addressed_cover_and_updates_versions(): void
    {
        $collection = $this->collection();
        $cover = $this->preparedCover([120, 40, 80]);

        $changed = app(HdRezkaCollectionCoverImporter::class)->apply($collection, $cover);
        $collection->refresh();
        $expectedPath = "catalog-collections/{$collection->public_id}/imported/{$cover->contentHash}.webp";

        $this->assertTrue($changed);
        $this->assertSame('uploads', $collection->cover_disk);
        $this->assertSame($expectedPath, $collection->cover_path);
        $this->assertSame('image/webp', $collection->cover_mime_type);
        $this->assertSame($cover->size, $collection->cover_size);
        $this->assertSame(1, $collection->cover_version);
        $this->assertSame(2, $collection->content_version);
        Storage::disk('uploads')->assertExists($expectedPath);
        $this->assertSame($cover->bytes, Storage::disk('uploads')->get($expectedPath));
    }

    public function test_apply_makes_the_imported_cover_tree_available_to_the_shared_runtime_group(): void
    {
        $root = storage_path('framework/testing/disks/hdrezka-cover-permissions-'.Str::uuid());
        File::ensureDirectoryExists($root, 02770);
        chmod($root, 02770);
        Storage::forgetDisk('uploads');
        config([
            'filesystems.disks.uploads.root' => $root,
        ]);

        try {
            $collection = $this->collection();
            $cover = $this->preparedCover([25, 125, 225]);

            app(HdRezkaCollectionCoverImporter::class)->apply($collection, $cover);
            $collection->refresh();
            $path = (string) $collection->cover_path;
            $absolutePath = Storage::disk('uploads')->path($path);

            clearstatcache(true, $absolutePath);

            $this->assertSame(02770, fileperms($root) & 07777);
            $this->assertSame(02770, fileperms($root.'/catalog-collections') & 07777);
            $this->assertSame(02770, fileperms(dirname($absolutePath)) & 07777);
            $this->assertSame(0660, fileperms($absolutePath) & 0777);
        } finally {
            Storage::forgetDisk('uploads');
            File::deleteDirectory($root);
        }
    }

    public function test_identical_cover_is_idempotent_and_does_not_bump_versions(): void
    {
        $collection = $this->collection();
        $cover = $this->preparedCover([50, 110, 170]);
        $importer = app(HdRezkaCollectionCoverImporter::class);

        $this->assertTrue($importer->apply($collection, $cover));
        $collection->refresh();
        $path = $collection->cover_path;
        $coverVersion = $collection->cover_version;
        $contentVersion = $collection->content_version;

        $this->assertFalse($importer->apply($collection, $cover));
        $collection->refresh();

        $this->assertSame($path, $collection->cover_path);
        $this->assertSame($coverVersion, $collection->cover_version);
        $this->assertSame($contentVersion, $collection->content_version);
        Storage::disk('uploads')->assertExists((string) $path);
    }

    public function test_replacement_deletes_the_previous_imported_file_after_commit(): void
    {
        $collection = $this->collection();
        $importer = app(HdRezkaCollectionCoverImporter::class);
        $first = $this->preparedCover([200, 20, 20]);
        $second = $this->preparedCover([20, 200, 20]);

        $this->assertTrue($importer->apply($collection, $first));
        $collection->refresh();
        $oldPath = (string) $collection->cover_path;

        $this->assertTrue($importer->apply($collection, $second));
        $collection->refresh();

        Storage::disk('uploads')->assertMissing($oldPath);
        Storage::disk('uploads')->assertExists((string) $collection->cover_path);
        $this->assertSame(2, $collection->cover_version);
        $this->assertSame(3, $collection->content_version);
    }

    public function test_invalid_remote_image_keeps_the_existing_cover_untouched(): void
    {
        $collection = $this->collection();
        $existingPath = "catalog-collections/{$collection->public_id}/imported/existing.webp";
        Storage::disk('uploads')->put($existingPath, 'existing-bytes', ['visibility' => 'private']);
        $collection->forceFill([
            'cover_disk' => 'uploads',
            'cover_path' => $existingPath,
            'cover_mime_type' => 'image/webp',
            'cover_size' => 14,
            'cover_version' => 4,
            'content_version' => 7,
        ])->save();
        $url = 'https://hdrezka.my/uploads/mini/14/aa/broken.jpg';
        Http::fake([
            $url => Http::response('not-an-image', 200, [
                'Content-Type' => 'image/jpeg',
                'Content-Length' => '12',
            ]),
        ]);

        $cover = app(HdRezkaCollectionCoverImporter::class)->prepare($url);
        $collection->refresh();

        $this->assertNull($cover);
        $this->assertSame($existingPath, $collection->cover_path);
        $this->assertSame(4, $collection->cover_version);
        $this->assertSame(7, $collection->content_version);
        Storage::disk('uploads')->assertExists($existingPath);
    }

    public function test_declared_oversized_response_is_rejected_before_decode(): void
    {
        $url = 'https://hdrezka.my/uploads/mini/14/aa/large.jpg';
        Http::fake([
            $url => Http::response('small', 200, [
                'Content-Type' => 'image/jpeg',
                'Content-Length' => '1000001',
            ]),
        ]);

        $this->assertNull(app(HdRezkaCollectionCoverImporter::class)->prepare($url));
    }

    public function test_apply_rejects_a_symlinked_managed_tree_that_escapes_the_upload_root(): void
    {
        $root = storage_path('framework/testing/disks/hdrezka-cover-root-'.Str::uuid());
        $outside = storage_path('framework/testing/disks/hdrezka-cover-outside-'.Str::uuid());
        File::ensureDirectoryExists($root, 02770);
        File::ensureDirectoryExists($outside, 02770);
        $link = $root.'/catalog-collections';

        if (! @symlink($outside, $link)) {
            File::deleteDirectory($root);
            File::deleteDirectory($outside);
            $this->markTestSkipped('Символические ссылки недоступны в текущем окружении.');
        }

        Storage::forgetDisk('uploads');
        config(['filesystems.disks.uploads.root' => $root]);

        try {
            $collection = $this->collection();
            $cover = $this->preparedCover([80, 120, 160]);
            $exception = null;

            try {
                app(HdRezkaCollectionCoverImporter::class)->apply($collection, $cover);
            } catch (RuntimeException $caught) {
                $exception = $caught;
            }

            $this->assertInstanceOf(RuntimeException::class, $exception);
            $this->assertNull($collection->fresh()?->cover_path);
            $this->assertSame([], File::allFiles($outside));
        } finally {
            Storage::forgetDisk('uploads');

            if (is_link($link)) {
                unlink($link);
            }

            File::deleteDirectory($root);
            File::deleteDirectory($outside);
        }
    }

    private function collection(): CatalogCollection
    {
        return CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'owner_id' => null,
            'name' => 'Импортированная подборка',
            'description' => null,
            'slug' => 'imported-'.Str::lower(Str::random(10)),
            'type' => CatalogCollectionType::Editorial,
            'visibility' => CatalogCollectionVisibility::Public,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'sort_mode' => CatalogCollectionSort::Manual,
            'content_locale' => 'ru',
            'is_featured' => false,
            'cover_version' => 0,
            'content_version' => 1,
            'published_at' => now(),
        ]);
    }

    private function preparedCover(array $color): object
    {
        $sourceBytes = $this->imageBytes(160, 90, 'jpeg', $color);
        $path = '/uploads/mini/14/aa/'.implode('-', $color).'.jpg';
        $url = 'https://hdrezka.my'.$path;
        Http::fake([
            $url => Http::response($sourceBytes, 200, [
                'Content-Type' => 'image/jpeg',
                'Content-Length' => (string) strlen($sourceBytes),
            ]),
        ]);
        $cover = app(HdRezkaCollectionCoverImporter::class)->prepare($url);

        $this->assertNotNull($cover);

        return $cover;
    }

    /** @param array{int, int, int} $color */
    private function imageBytes(int $width, int $height, string $format, array $color): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$color));
        ob_start();

        try {
            $encoded = $format === 'png'
                ? imagepng($image, null, 6)
                : imagejpeg($image, null, 88);
            $bytes = ob_get_clean();
        } finally {
            imagedestroy($image);
        }

        $this->assertTrue($encoded);
        $this->assertIsString($bytes);

        return $bytes;
    }
}
