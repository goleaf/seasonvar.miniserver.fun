<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiErrorResponse;
use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureMobileEmailIsVerified
{
    public function __construct(private ApiErrorResponse $errors) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return $this->errors->make(
                $request,
                'email_not_verified',
                __('auth.errors.email_not_verified'),
                403,
            );
        }

        return $next($request);
    }
}
