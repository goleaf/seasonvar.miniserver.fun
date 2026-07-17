<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            AdminSeeder::class,
        ]);

        if (app()->environment('dev') && config('demo-data.enabled')) {
            $this->call(PortalDemoSeeder::class);
        }
    }
}
