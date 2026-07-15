<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Services\Auth\AccountPasswordResetService;
use Illuminate\Http\JsonResponse;

final class ForgotPasswordController extends Controller
{
    public function __invoke(
        ForgotPasswordRequest $request,
        AccountPasswordResetService $passwords,
    ): JsonResponse {
        $passwords->sendResetLink($request->email());

        return response()->json(['data' => [
            'status' => AccountPasswordResetService::REQUEST_STATUS,
        ]], headers: ['Cache-Control' => 'private, no-store']);
    }
}
