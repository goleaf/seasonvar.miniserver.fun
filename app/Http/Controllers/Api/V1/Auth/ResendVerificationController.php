<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ResendVerificationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['data' => [
            'status' => $user->hasVerifiedEmail()
                ? 'Адрес электронной почты уже подтверждён.'
                : 'Письмо для подтверждения отправлено.',
        ]], headers: ['Cache-Control' => 'private, no-store']);
    }
}
