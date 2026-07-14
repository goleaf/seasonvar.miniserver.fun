<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApiErrorResponse
{
    /** @param array<string, list<string>> $errors */
    public function make(
        Request $request,
        string $code,
        string $message,
        int $status,
        array $errors = [],
    ): JsonResponse {
        $body = [
            'code' => $code,
            'message' => $message,
        ];

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        $body['request_id'] = (string) $request->attributes->get('api_request_id');

        return response()->json($body, $status, [
            'Cache-Control' => 'private, no-store',
            'X-Request-ID' => (string) $request->attributes->get('api_request_id'),
        ]);
    }
}
