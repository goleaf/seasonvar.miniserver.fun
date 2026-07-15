<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use LogicException;

final class BrowserSessionService
{
    public function synchronizeCurrentPasswordHash(User $user): void
    {
        Session::put(
            'password_hash_'.Auth::getDefaultDriver(),
            $user->getAuthPassword(),
        );
    }

    public function logoutOtherDevices(User $user, string $password, string $currentSessionId): void
    {
        $guard = Auth::guard('web');

        if (! $guard instanceof SessionGuard) {
            throw new LogicException('The web guard must use the session driver.');
        }

        if (! Hash::check($password, $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'current_password' => ['Текущий пароль указан неверно.'],
            ]);
        }

        $guard->logoutOtherDevices($password);
        $user->refresh();
        $this->synchronizeCurrentPasswordHash($user);
        $this->deleteOtherDatabaseSessions($user, $currentSessionId);
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
}
