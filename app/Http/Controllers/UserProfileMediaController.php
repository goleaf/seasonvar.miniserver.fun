<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Profiles\UserProfileResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class UserProfileMediaController extends Controller
{
    public function __invoke(
        Request $request,
        string $userPublicId,
        string $kind,
        int $version,
        UserProfileResolver $profiles,
    ): Response {
        abort_unless(in_array($kind, ['avatar', 'cover'], true), 404);
        $profile = $profiles->byUserPublicId($userPublicId);
        Gate::authorize('view', $profile);
        $diskName = $profile->getAttribute($kind.'_disk');
        $path = $profile->getAttribute($kind.'_path');
        $mimeType = $profile->getAttribute($kind.'_mime_type');
        abort_unless(is_string($diskName) && is_string($path) && is_string($mimeType), 404);
        abort_unless((int) $profile->getAttribute($kind.'_version') === $version, 404);
        abort_unless($diskName === config('uploads.disk'), 404);
        abort_unless(in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true), 404);
        abort_unless(str_starts_with($path, 'user-profiles/'.$userPublicId.'/'.$kind.'/'), 404);
        abort_if(str_contains($path, '..') || str_contains($path, '\\'), 404);
        $disk = Storage::disk($diskName);
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
