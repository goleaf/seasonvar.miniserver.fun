<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DeviceTokenResource;
use App\Models\User;
use App\Services\Auth\MobileTokenService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

final class TokenController extends Controller
{
    public function index(Request $request, MobileTokenService $tokens): JsonResponse
    {
        return DeviceTokenResource::collection($tokens->devices($this->user($request)))
            ->response()
            ->header('Cache-Control', 'private, no-store');
    }

    public function refresh(Request $request, MobileTokenService $tokens): JsonResponse
    {
        $result = $tokens->rotate($this->user($request), $this->currentToken($request));

        return response()->json(['data' => [
            'token' => $result->token,
            'token_type' => 'Bearer',
            'expires_at' => $result->expiresAt->toJSON(),
        ]], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function logout(Request $request, MobileTokenService $tokens): Response
    {
        $tokens->revoke($this->user($request), (int) $this->currentToken($request)->getKey());

        return response()->noContent(headers: ['Cache-Control' => 'private, no-store']);
    }

    public function logoutAll(Request $request, MobileTokenService $tokens): Response
    {
        $tokens->revokeAll($this->user($request));

        return response()->noContent(headers: ['Cache-Control' => 'private, no-store']);
    }

    public function destroy(Request $request, int $token, MobileTokenService $tokens): Response
    {
        $tokens->revoke($this->user($request), $token);

        return response()->noContent(headers: ['Cache-Control' => 'private, no-store']);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    private function currentToken(Request $request): PersonalAccessToken
    {
        return $this->user($request)->currentAccessToken();
    }
}
