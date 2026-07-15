<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class ApiDiscoveryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'service' => 'Seasonvar Mobile API',
                'current_version' => (string) config('mobile-api.version'),
                'minimum_supported_version' => (string) config('mobile-api.minimum_supported_version'),
                'base_url' => url('/api/v1'),
                'openapi_url' => url('/api/openapi.json'),
                'capabilities' => [
                    'catalog',
                    'public_collections',
                    'authentication',
                    'user_state',
                    'personal_tags',
                    'playback',
                    'offline_sync',
                ],
            ],
        ]);
    }
}
