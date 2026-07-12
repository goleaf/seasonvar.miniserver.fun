<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LicensedMedia;
use App\Models\User;
use App\Services\Catalog\CatalogPlaybackSourceResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PlaybackSourceController extends Controller
{
    public function __invoke(
        Request $request,
        LicensedMedia $licensedMedia,
        CatalogPlaybackSourceResolver $sources,
    ): Response {
        $user = $request->user();
        $user = $user instanceof User ? $user : null;

        abort_unless((int) $request->query('viewer', -1) === (int) ($user?->id ?? 0), 403);

        return $sources->response($licensedMedia, $user);
    }
}
