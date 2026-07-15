<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Services\Auth\AccountPasswordResetService;
use Illuminate\Http\JsonResponse;

final class ResetPasswordController extends Controller
{
    public function __invoke(
        ResetPasswordRequest $request,
        AccountPasswordResetService $passwords,
    ): JsonResponse {
        $data = $request->resetData();
        $passwords->reset($data['email'], $data['token'], $data['password']);

        return response()->json(['data' => [
            'status' => 'Пароль успешно изменён.',
        ]], headers: ['Cache-Control' => 'private, no-store']);
    }
}
