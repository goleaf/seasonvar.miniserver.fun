<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LocalAccountSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_accounts_are_seeded_idempotently_with_documented_credentials(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@example.com')->sole();
        $user = User::query()->where('email', 'user@example.com')->sole();

        $this->assertNotNull($admin->email_verified_at);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertSame(2, User::query()->whereIn('email', [
            'admin@example.com',
            'user@example.com',
        ])->count());
    }

    public function test_only_the_configured_admin_account_receives_administrator_access(): void
    {
        config(['seasonvar.admin_emails' => ['admin@example.com']]);

        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@example.com')->sole();
        $user = User::query()->where('email', 'user@example.com')->sole();

        $this->assertTrue(Gate::forUser($admin)->allows('manage-catalog'));
        $this->assertFalse(Gate::forUser($user)->allows('manage-catalog'));
    }
}
