<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Auth\MobileAuthenticationService;
use Illuminate\Http\JsonResponse;

final class LoginController extends Controller
{
    public function __invoke(
        LoginRequest $request,
        MobileAuthenticationService $authentication,
    ): JsonResponse {
        $result = $authentication->login(
            $request->email(),
            $request->password(),
            $request->deviceName(),
        );

        return response()->json(['data' => [
            'user' => (new UserResource($result['user']))->resolve($request),
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'expires_at' => $result['expires_at']->toJSON(),
        ]], headers: ['Cache-Control' => 'private, no-store']);
    }
}
