<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ResetPasswordController extends Controller
{
    public function __invoke(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->resetData();
        $user = User::query()->whereRaw('lower(email) = ?', [$data['email']])->first();
        $status = Password::reset([
            'email' => $user?->email ?? $data['email'],
            'token' => $data['token'],
            'password' => $data['password'],
            'password_confirmation' => $data['password'],
        ], function (User $user, string $password): void {
            DB::transaction(function () use ($user, $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
                $user->tokens()->delete();
                event(new PasswordReset($user));
            }, attempts: 3);
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['Не удалось сбросить пароль с указанными данными.'],
            ]);
        }

        return response()->json(['data' => [
            'status' => 'Пароль успешно изменён.',
        ]], headers: ['Cache-Control' => 'private, no-store']);
    }
}
