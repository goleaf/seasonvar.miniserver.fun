<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogCollection;
use App\Services\Collections\CatalogCollectionCoverService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogCollectionCoverFallbackTest extends TestCase
{
    public function test_url_is_not_generated_for_a_legacy_path_rejected_by_the_responder(): void
    {
        Storage::fake('uploads');
        config(['uploads.disk' => 'uploads']);
        $publicId = (string) Str::uuid();
        $path = 'demo-data/seasonvar-demo-v1/collection-covers/legacy.webp';
        Storage::disk('uploads')->put($path, 'legacy');
        $collection = (new CatalogCollection)->forceFill([
            'public_id' => $publicId,
            'cover_disk' => 'uploads',
            'cover_path' => $path,
            'cover_mime_type' => 'image/webp',
            'cover_size' => 6,
            'cover_version' => 1,
        ]);

        $this->assertNull(app(CatalogCollectionCoverService::class)->url($collection));
    }
}
