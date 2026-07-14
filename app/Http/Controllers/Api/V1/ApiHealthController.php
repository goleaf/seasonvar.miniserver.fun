<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class ApiHealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'status' => 'ok',
                'server_time' => now()->utc()->toISOString(),
                'api_version' => (string) config('mobile-api.version'),
            ],
        ], headers: ['Cache-Control' => 'private, no-store']);
    }
}
