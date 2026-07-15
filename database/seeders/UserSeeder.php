<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'user@example.com'],
            [
                'name' => 'Пользователь',
                'email_verified_at' => now(),
                'password' => 'password',
            ],
        );
    }
}
