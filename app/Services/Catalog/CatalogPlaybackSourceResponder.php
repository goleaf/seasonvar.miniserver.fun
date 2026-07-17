<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\LicensedMedia;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class CatalogPlaybackSourceResponder
{
    public function __construct(private CatalogPlaybackSourceResolver $sources) {}

    public function response(Request $request, LicensedMedia $licensedMedia): Response
    {
        $viewer = $request->user();
        $viewer = $viewer instanceof User ? $viewer : null;
        $viewerId = $viewer === null ? 0 : (int) $viewer->getKey();

        abort_unless((int) $request->query('viewer', -1) === $viewerId, 403);

        return $this->sources->response($licensedMedia, $viewer);
    }
}
