<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LicensedMedia;
use App\Models\User;
use App\Services\Catalog\CatalogPlaybackSourceResolver;
use App\Services\Catalog\MobilePlaybackGrant;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class PlaybackSourceController extends Controller
{
    public function __invoke(
        Request $request,
        LicensedMedia $licensedMedia,
        MobilePlaybackGrant $grants,
        CatalogPlaybackSourceResolver $sources,
    ): Response {
        $grant = $grants->resolve((string) $request->query('grant'), $licensedMedia);

        if ($grant === null) {
            throw new AccessDeniedHttpException;
        }

        $user = $grant->userId === null
            ? null
            : User::query()->find($grant->userId);

        if ($grant->userId !== null && ! $user instanceof User) {
            throw new AccessDeniedHttpException;
        }

        return $sources->response($licensedMedia, $user);
    }
}
