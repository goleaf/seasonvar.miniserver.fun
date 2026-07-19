<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\WarmUserPortalCache as WarmUserPortalCacheJob;
use App\Models\User;
use App\Services\UserPortal\UserPortalCacheWarmer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Signature('cache:warm-user-portal
    {users?* : Exact public UUID, username или email пользователя}
    {--all-demo : Выбрать только user1@example.com … userN@example.com}
    {--refresh : Пересобрать текущий owner namespace}')]
#[Description('Прогревает безопасные owner-scoped snapshots личного кабинета')]
final class WarmUserPortalCache extends Command
{
    public function handle(UserPortalCacheWarmer $warmer): int
    {
        $users = $this->resolveUsers();

        if ($users->isEmpty()) {
            $this->error('Не найдено ни одного пользователя для прогрева.');

            return self::FAILURE;
        }

        $refresh = (bool) $this->option('refresh');

        if ($users->count() === 1) {
            $user = $users->first();
            $result = $warmer->warm($user, $refresh);
            $this->info("Прогрет пользователь {$user->public_id}: {$result['targets']} snapshots за {$result['duration_ms']} мс.");

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            WarmUserPortalCacheJob::dispatch((string) $user->public_id, $refresh);
        }

        $this->info('Поставлено в очередь пользователей: '.$users->count().'.');

        return self::SUCCESS;
    }

    /** @return Collection<int, User> */
    private function resolveUsers(): Collection
    {
        if ((bool) $this->option('all-demo')) {
            return User::query()
                ->where('email', 'like', 'user%@example.com')
                ->orderBy('id')
                ->limit(1_000)
                ->get()
                ->filter(fn (User $user): bool => preg_match('/^user\d+@example\.com$/D', Str::lower($user->email)) === 1)
                ->values();
        }

        $identifiers = collect((array) $this->argument('users'))
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '' && mb_strlen($value) <= 255)
            ->map(fn (string $value): string => Str::lower(trim($value)))
            ->unique()
            ->take(1_000)
            ->values();

        if ($identifiers->isEmpty()) {
            return collect();
        }

        return User::query()
            ->where(function ($query) use ($identifiers): void {
                $query
                    ->whereIn('public_id', $identifiers)
                    ->orWhereIn('email', $identifiers)
                    ->orWhereHas('profile', fn ($profile) => $profile->whereIn('normalized_username', $identifiers));
            })
            ->orderBy('id')
            ->limit(1_000)
            ->get();
    }
}
