<?php

declare(strict_types=1);

namespace App\Services\Collections;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final readonly class CatalogCollectionCoverResponder
{
    public function __construct(private CatalogCollectionResolver $resolver) {}

    public function response(Request $request, string $publicId, int $version): Response
    {
        $collection = $this->resolver->byPublicId($publicId);
        Gate::authorize('view', $collection);
        abort_unless($collection->cover_path !== null && $collection->cover_disk !== null, 404);
        abort_unless($collection->cover_version === $version, 404);
        abort_unless($collection->cover_disk === config('uploads.disk'), 404);
        abort_unless(in_array($collection->cover_mime_type, ['image/jpeg', 'image/png', 'image/webp'], true), 404);
        abort_unless(str_starts_with($collection->cover_path, 'catalog-collections/'.$collection->public_id.'/'), 404);
        abort_if(str_contains($collection->cover_path, '..') || str_contains($collection->cover_path, '\\'), 404);
        $disk = Storage::disk($collection->cover_disk);
        abort_unless($disk->exists($collection->cover_path), 404);

        return $disk->response($collection->cover_path, null, [
            'Content-Type' => $collection->cover_mime_type,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
