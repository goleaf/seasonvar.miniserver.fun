<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

final class ForgotPasswordController extends Controller
{
    public function __invoke(ForgotPasswordRequest $request): JsonResponse
    {
        $user = User::query()->whereRaw('lower(email) = ?', [$request->email()])->first();

        Password::sendResetLink(['email' => $user?->email ?? $request->email()]);

        return response()->json(['data' => [
            'status' => 'Если аккаунт существует, письмо для восстановления отправлено.',
        ]], headers: ['Cache-Control' => 'private, no-store']);
    }
}
