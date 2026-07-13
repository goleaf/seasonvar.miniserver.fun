<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Operations\InfrastructureHealthCheck;
use Illuminate\Http\JsonResponse;

final class InfrastructureHealthController extends Controller
{
    public function __invoke(InfrastructureHealthCheck $health): JsonResponse
    {
        $result = $health->run();

        return response()->json($result, $result['ready'] ? 200 : 503, [
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
