<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AccountRegistrationService
{
    /** @param array{name: string, email: string, password: string} $attributes */
    public function register(array $attributes): User
    {
        $name = Str::squish($attributes['name']);
        $email = Str::lower(Str::squish($attributes['email']));

        $user = DB::transaction(function () use ($attributes, $name, $email): User {
            DB::table('password_reset_tokens')
                ->whereRaw('lower(email) = ?', [$email])
                ->delete();

            return User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $attributes['password'],
            ]);
        }, attempts: 3);

        $user->sendEmailVerificationNotification();

        return $user;
    }
}
