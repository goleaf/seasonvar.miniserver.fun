<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AccountEmailVerificationService;
use Illuminate\Http\JsonResponse;

final class VerifyEmailController extends Controller
{
    public function __invoke(
        AccountEmailVerificationService $verification,
        int $id,
        string $hash,
    ): JsonResponse {
        $verification->verify($id, $hash);

        return response()->json(['data' => [
            'email_verified' => true,
            'status' => 'Адрес электронной почты подтверждён.',
        ]], headers: ['Cache-Control' => 'private, no-store']);
    }
}
