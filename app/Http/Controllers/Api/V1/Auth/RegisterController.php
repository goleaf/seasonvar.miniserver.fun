<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Auth\MobileAuthenticationService;
use App\Services\Auth\RegistrationAvailability;
use Illuminate\Http\JsonResponse;

final class RegisterController extends Controller
{
    public function __invoke(
        RegisterRequest $request,
        MobileAuthenticationService $authentication,
        RegistrationAvailability $registration,
    ): JsonResponse {
        $registration->ensureEnabled();
        $result = $authentication->register($request->registrationData());

        return response()->json(['data' => [
            'user' => (new UserResource($result['user']))->resolve($request),
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'expires_at' => $result['expires_at']->toJSON(),
        ]], 201, ['Cache-Control' => 'private, no-store']);
    }
}
