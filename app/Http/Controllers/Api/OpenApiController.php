<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class OpenApiController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $schema = json_decode(
            (string) file_get_contents(resource_path('api/openapi.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return response()->json($schema);
    }
}
