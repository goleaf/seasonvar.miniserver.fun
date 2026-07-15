<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Collections\CatalogCollectionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class CatalogCollectionCoverController extends Controller
{
    public function __invoke(
        Request $request,
        string $publicId,
        int $version,
        CatalogCollectionResolver $resolver,
    ): Response {
        $collection = $resolver->byPublicId($publicId);
        Gate::authorize('view', $collection);
        abort_unless($collection->cover_path !== null && $collection->cover_disk !== null, 404);
        abort_unless($collection->cover_version === $version, 404);
        abort_unless($collection->cover_disk === config('uploads.disk'), 404);
        abort_unless(str_starts_with($collection->cover_path, 'catalog-collections/'.$collection->public_id.'/'), 404);
        abort_if(str_contains($collection->cover_path, '..') || str_contains($collection->cover_path, '\\'), 404);
        $headers = [
            'Content-Type' => $collection->cover_mime_type ?? 'application/octet-stream',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ];

        $headers['Cache-Control'] = 'private, no-store, max-age=0';
        $headers['Pragma'] = 'no-cache';

        return Storage::disk($collection->cover_disk)->response($collection->cover_path, null, $headers);
    }
}
