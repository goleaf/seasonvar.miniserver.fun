<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'development', 'dev', 'testing'])) {
            $this->command?->warn(
                'DatabaseSeeder пропущен: development/demo данные запрещены в текущей среде.',
            );

            return;
        }

        $this->call([
            UserSeeder::class,
            AdminSeeder::class,
        ]);

        if (app()->environment('dev') && config('demo-data.enabled')) {
            $this->call(PortalDemoSeeder::class);
        }
    }
}
