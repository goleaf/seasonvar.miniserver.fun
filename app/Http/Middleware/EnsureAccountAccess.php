<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\AccountAccessResolver;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureAccountAccess
{
    public function __construct(private AccountAccessResolver $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $this->access->canAuthenticate($user)) {
            if (! $request->is('api/*')) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                abort(403, __('auth.errors.forbidden'));
            }

            throw new AuthorizationException(__('auth.errors.forbidden'));
        }

        return $next($request);
    }
}
