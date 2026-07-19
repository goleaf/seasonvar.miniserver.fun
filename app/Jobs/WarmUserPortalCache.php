<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\UserPortal\UserPortalCacheWarmer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class WarmUserPortalCache implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout;

    public int $uniqueFor;

    public function __construct(
        public readonly string $userPublicId,
        public readonly bool $refresh = false,
    ) {
        $this->timeout = max(30, min(600, (int) config('cache-architecture.warming.timeout', 600)));
        $this->uniqueFor = max(30, (int) config('cache-architecture.warming.unique_seconds', 604_800));
        $this->onConnection((string) config('cache-architecture.warming.connection', 'redis'));
        $this->onQueue((string) config('cache-architecture.warming.queue', 'cache-warm-v2'));
        $this->afterCommit();
    }

    public function handle(UserPortalCacheWarmer $warmer): void
    {
        $user = User::query()->where('public_id', $this->userPublicId)->first();

        if ($user instanceof User) {
            $warmer->warm($user, $this->refresh);
        }
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function uniqueId(): string
    {
        return 'user-portal:'.$this->userPublicId;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store((string) config('cache-architecture.stores.locks', 'redis-locks'));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Фоновый прогрев пользовательского портала завершился ошибкой.', [
            'user_public_id_hash' => hash('sha256', $this->userPublicId),
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }
}
