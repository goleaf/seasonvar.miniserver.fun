<?php

namespace Tests\Unit;

use App\Services\Storage\PrivateUploadStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class PrivateUploadStorageTest extends TestCase
{
    public function test_it_stores_uploads_on_private_disk_with_generated_filename(): void
    {
        Storage::fake('uploads');

        $upload = app(PrivateUploadStorage::class)->store(
            UploadedFile::fake()->image('client-poster-name.jpg')->size(128),
            'catalog/posters',
        );

        $this->assertSame('uploads', $upload->disk);
        $this->assertSame('private', $upload->visibility);
        $this->assertStringStartsWith('catalog/posters/', $upload->path);
        $this->assertStringNotContainsString('client-poster-name', $upload->path);

        Storage::disk('uploads')->assertExists($upload->path);
        $this->assertSame('private', Storage::disk('uploads')->getVisibility($upload->path));
    }

    public function test_it_rejects_unsafe_upload_directories(): void
    {
        Storage::fake('uploads');

        $this->expectException(InvalidArgumentException::class);

        app(PrivateUploadStorage::class)->store(
            UploadedFile::fake()->image('poster.jpg')->size(128),
            '../public',
        );
    }

    public function test_it_deletes_private_uploads_for_cleanup(): void
    {
        Storage::fake('uploads');

        $storage = app(PrivateUploadStorage::class);
        $upload = $storage->store(
            UploadedFile::fake()->image('poster.jpg')->size(128),
            'catalog/posters',
        );

        $this->assertTrue($storage->delete($upload));

        Storage::disk('uploads')->assertMissing($upload->path);
    }

    public function test_it_rejects_absolute_windows_and_null_byte_paths_for_storage_and_deletion(): void
    {
        Storage::fake('uploads');
        $storage = app(PrivateUploadStorage::class);

        foreach (['/etc', 'C:\\temp', "catalog\0posters"] as $directory) {
            try {
                $storage->store(
                    UploadedFile::fake()->image('poster.jpg')->size(128),
                    $directory,
                );
                $this->fail("Directory [{$directory}] was not rejected.");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        foreach (['/etc/passwd', 'C:\\private.txt', "catalog\0private.txt", 'catalog/../private.txt'] as $path) {
            $this->assertFalse($storage->delete($path));
        }
    }
}
