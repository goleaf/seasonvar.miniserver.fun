<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\User;
use App\Services\Media\StreamLicensedMediaDownload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class DownloadLicensedMediaController extends Controller
{
    public function __invoke(
        Request $request,
        CatalogTitle $catalogTitle,
        LicensedMedia $licensedMedia,
        StreamLicensedMediaDownload $downloads,
    ): Response {
        abort_unless((int) $licensedMedia->catalog_title_id === $catalogTitle->id, 404);

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless(Gate::forUser($user)->allows('download', $licensedMedia), 404);

        return $downloads->response($request, $user, $catalogTitle, $licensedMedia);
    }
}
