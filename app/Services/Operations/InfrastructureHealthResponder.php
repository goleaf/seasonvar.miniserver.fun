<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Illuminate\Http\JsonResponse;

final readonly class InfrastructureHealthResponder
{
    public function __construct(private InfrastructureHealthCheck $health) {}

    public function response(): JsonResponse
    {
        $result = $this->health->run();

        return response()->json($result, $result['ready'] ? 200 : 503, [
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
