<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pwa\VapidKeyGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pwa:vapid-generate {--json : Вывести машинно-читаемый JSON}')]
#[Description('Создаёт новую VAPID P-256 пару без записи ключей на диск')]
final class GeneratePwaVapidKeys extends Command
{
    public function handle(VapidKeyGenerator $keys): int
    {
        $pair = $keys->generate();

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'status' => 'generated',
                ...$pair,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->warn('Private key будет показан один раз. Используйте только защищённый терминал и secret storage.');
        $this->newLine();
        $this->line('PWA_VAPID_PUBLIC_KEY='.$pair['public_key']);
        $this->line('PWA_VAPID_PRIVATE_KEY='.$pair['private_key']);

        return self::SUCCESS;
    }
}
