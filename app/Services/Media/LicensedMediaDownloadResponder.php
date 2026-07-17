<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final readonly class LicensedMediaDownloadResponder
{
    public function __construct(private StreamLicensedMediaDownload $downloads) {}

    public function response(
        Request $request,
        CatalogTitle $catalogTitle,
        LicensedMedia $licensedMedia,
    ): Response {
        abort_unless((int) $licensedMedia->catalog_title_id === $catalogTitle->id, 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless(Gate::forUser($user)->allows('download', $licensedMedia), 404);

        return $this->downloads->response($request, $user, $catalogTitle, $licensedMedia);
    }
}
