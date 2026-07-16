<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\AuthenticationEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiErrorResponse;
use App\Models\User;
use App\Services\Auth\AuthenticationAuditService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ResendVerificationController extends Controller
{
    public function __invoke(
        Request $request,
        AuthenticationAuditService $audit,
        ApiErrorResponse $errors,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        if (! $user->hasVerifiedEmail()) {
            try {
                $user->sendEmailVerificationNotification();
            } catch (\Throwable $exception) {
                report($exception);

                return $errors->make(
                    $request,
                    'mail_delivery_failed',
                    __('auth.errors.mail_delivery_failed'),
                    503,
                );
            }

            $audit->record(AuthenticationEvent::VerificationRequested, $user, $user->email);
        }

        return response()->json(['data' => [
            'status' => $user->hasVerifiedEmail()
                ? __('auth.status.email_already_verified')
                : __('auth.status.verification_sent_api'),
        ]], headers: ['Cache-Control' => 'private, no-store']);
    }
}
