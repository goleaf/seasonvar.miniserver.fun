<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeleteAccountRequest;
use App\Http\Requests\Api\V1\UpdatePasswordRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\Auth\AccountService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return (new UserResource($this->user($request)))
            ->response()
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(UpdateProfileRequest $request, AccountService $accounts): JsonResponse
    {
        $user = $accounts->updateProfile($this->user($request), $request->profileData());

        return (new UserResource($user))
            ->response()
            ->header('Cache-Control', 'private, no-store');
    }

    public function updatePassword(UpdatePasswordRequest $request, AccountService $accounts): JsonResponse
    {
        $currentToken = $this->user($request)->currentAccessToken();
        $accounts->updatePassword(
            $this->user($request),
            $request->currentPassword(),
            $request->newPassword(),
            (int) $currentToken->getKey(),
        );

        return response()->json(['data' => [
            'status' => 'Пароль успешно изменён.',
        ]], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function destroy(DeleteAccountRequest $request, AccountService $accounts): Response
    {
        $accounts->delete($this->user($request), $request->password());

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
}
