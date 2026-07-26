<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Symfony\Component\HttpFoundation\Response;

final class PlaybackQualityNetworkTestResponder
{
    public function response(): Response
    {
        return response('', 204, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }
}
