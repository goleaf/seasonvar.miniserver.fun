<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTOs\BrowserSessionSummaryData;
use App\Enums\AuthenticationEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use LogicException;

final class BrowserSessionService
{
    public function __construct(
        private readonly AccountDateTimeFormatter $dateTimes,
        private readonly AuthenticationAuditService $audit,
    ) {}

    public function synchronizeCurrentPasswordHash(User $user): void
    {
        Session::put(
            'password_hash_'.Auth::getDefaultDriver(),
            $user->getAuthPassword(),
        );
    }

    public function logoutOtherDevices(User $user, string $password, string $currentSessionId): void
    {
        $rateKey = 'browser-session-logout-others:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($rateKey, 6)) {
            throw ValidationException::withMessages([
                'current_password' => [__('settings.security_page.security_rate_limited')],
            ]);
        }

        RateLimiter::hit($rateKey, 120);
        $guard = Auth::guard('web');

        if (! $guard instanceof SessionGuard) {
            throw new LogicException('The web guard must use the session driver.');
        }

        if (! Hash::check($password, $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'current_password' => [__('settings.security_page.current_password_invalid')],
            ]);
        }

        $guard->logoutOtherDevices($password);
        $user->refresh();
        $this->synchronizeCurrentPasswordHash($user);
        $this->deleteOtherDatabaseSessions($user, $currentSessionId);
        RateLimiter::clear($rateKey);
        $this->audit->record(AuthenticationEvent::OtherBrowserSessionsRevoked, $user, $user->email);
    }

    /** @return Collection<int, BrowserSessionSummaryData> */
    public function summaries(
        User $user,
        string $currentSessionId,
        string $locale,
        string $timezone,
    ): Collection {
        if (config('session.driver') !== 'database') {
            return collect();
        }

        $table = config('session.table', 'sessions');
        $connection = config('session.connection');

        if (! is_string($table) || $table === '') {
            return collect();
        }

        return DB::connection(is_string($connection) && $connection !== '' ? $connection : null)
            ->table($table)
            ->where('user_id', $user->getKey())
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$currentSessionId])
            ->orderByDesc('last_activity')
            ->limit(20)
            ->get(['id', 'user_agent', 'last_activity'])
            ->map(function (object $session) use ($user, $currentSessionId, $locale, $timezone): BrowserSessionSummaryData {
                $sessionId = (string) $session->id;
                $current = hash_equals($currentSessionId, $sessionId);
                $lastActivity = max(0, (int) $session->last_activity);

                return new BrowserSessionSummaryData(
                    wireKey: $current ? 'current-'.$this->opaqueToken($user, $sessionId) : $this->opaqueToken($user, $sessionId),
                    opaqueToken: $current ? null : $this->opaqueToken($user, $sessionId),
                    deviceLabel: $this->deviceLabel(is_string($session->user_agent) ? $session->user_agent : ''),
                    lastActivityLabel: $this->dateTimes->timestamp($lastActivity, $locale, $timezone),
                    lastActivityIso: CarbonImmutable::createFromTimestamp($lastActivity, 'UTC')->toAtomString(),
                    current: $current,
                );
            });
    }

    public function revoke(
        User $user,
        string $password,
        string $opaqueToken,
        string $currentSessionId,
    ): void {
        $rateKey = 'browser-session-revoke:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($rateKey, 12)) {
            throw ValidationException::withMessages([
                'session' => [__('settings.security_page.session_rate_limited')],
            ]);
        }

        RateLimiter::hit($rateKey, 60);

        if (config('session.driver') !== 'database' || preg_match('/^[a-f0-9]{64}$/', $opaqueToken) !== 1) {
            throw ValidationException::withMessages([
                'session' => [__('settings.security_page.session_not_found')],
            ]);
        }

        if (! Hash::check($password, $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'current_password' => [__('settings.security_page.current_password_invalid')],
            ]);
        }
        $table = config('session.table', 'sessions');
        $connection = config('session.connection');

        if (! is_string($table) || $table === '') {
            throw ValidationException::withMessages([
                'session' => [__('settings.security_page.session_not_found')],
            ]);
        }

        $database = DB::connection(is_string($connection) && $connection !== '' ? $connection : null);
        $sessionId = $database->table($table)
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $currentSessionId)
            ->orderByDesc('last_activity')
            ->limit(20)
            ->pluck('id')
            ->first(fn (mixed $id): bool => is_string($id) && hash_equals($this->opaqueToken($user, $id), $opaqueToken));

        if (! is_string($sessionId)) {
            throw ValidationException::withMessages([
                'session' => [__('settings.security_page.session_not_found')],
            ]);
        }

        $deleted = $database->table($table)
            ->where('user_id', $user->getKey())
            ->where('id', $sessionId)
            ->where('id', '!=', $currentSessionId)
            ->delete();

        if ($deleted !== 1) {
            throw ValidationException::withMessages([
                'session' => [__('settings.security_page.session_not_found')],
            ]);
        }

        $this->audit->record(AuthenticationEvent::BrowserSessionRevoked, $user, $user->email);
    }

    private function deleteOtherDatabaseSessions(User $user, string $currentSessionId): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $table = config('session.table', 'sessions');
        $connection = config('session.connection');

        if (! is_string($table) || $table === '') {
            return;
        }

        DB::connection(is_string($connection) && $connection !== '' ? $connection : null)
            ->table($table)
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $currentSessionId)
            ->delete();
    }

    private function opaqueToken(User $user, string $sessionId): string
    {
        return hash_hmac('sha256', $user->getKey().'|'.$sessionId, (string) config('app.key'));
    }

    private function deviceLabel(string $userAgent): string
    {
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Microsoft Edge',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => __('settings.security_page.unknown_browser'),
        };
        $device = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS / iPadOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => __('settings.security_page.unknown_device'),
        };

        return $browser.' · '.$device;
    }
}
