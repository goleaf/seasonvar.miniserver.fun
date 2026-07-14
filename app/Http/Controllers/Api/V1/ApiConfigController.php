<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class ApiConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'locale' => (string) config('app.locale', 'ru'),
                'timezone' => (string) config('app.timezone', 'UTC'),
                'pagination' => [
                    'default_per_page' => (int) config('mobile-api.default_per_page', 20),
                    'maximum_per_page' => (int) config('mobile-api.maximum_per_page', 50),
                ],
                'user_rating' => [
                    'minimum' => (int) config('catalog.user_rating.minimum', 1),
                    'maximum' => (int) config('catalog.user_rating.maximum', 10),
                ],
                'playback' => [
                    'formats' => array_values((array) config('playback.allowed_formats', [])),
                    'qualities' => array_values((array) config('playback.supported_qualities', [])),
                    'url_ttl_seconds' => max(30, min(3600, (int) config('playback.signed_url_ttl_seconds', 300))),
                    'progress_session_ttl_seconds' => max(300, min(86400, (int) config('playback.progress.session_ttl_seconds', 21600))),
                    'progress_heartbeat_seconds' => (int) config('mobile-api.progress_heartbeat_seconds', 15),
                ],
            ],
        ]);
    }
}
