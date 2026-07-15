<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

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
        try {
            Auth::guard('web')->logoutOtherDevices($password);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'current_password' => ['Текущий пароль указан неверно.'],
            ]);
        }

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
