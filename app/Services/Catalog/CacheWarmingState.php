<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheKeyFactory;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class CacheWarmingState
{
    public function __construct(
        private readonly CacheKeyFactory $keys,
        private readonly CacheVersionRegistry $versions,
    ) {}

    public function started(): void
    {
        $this->write(['status' => 'running', 'started_at' => now()->toIso8601String()]);
    }

    /** @param array<string, mixed> $result */
    public function succeeded(array $result): void
    {
        $this->write([
            'status' => ((int) ($result['failed'] ?? 0)) > 0 ? 'degraded' : 'ok',
            ...$result,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $this->write([
            'status' => 'failed',
            'failed_at' => now()->toIso8601String(),
            'exception' => $exception::class,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        try {
            $state = Cache::memo($this->store())->get($this->key());

            return is_array($state) ? $state : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $state */
    private function write(array $state): void
    {
        try {
            Cache::memo($this->store())->put(
                $this->key(),
                $state,
                max(60, (int) config('cache-architecture.operations.warming_state_retention_seconds', 604_800)),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function key(): string
    {
        return $this->keys->data(
            CacheDomain::Operational,
            'warming-state',
            [],
            $this->versions->version(CacheDomain::Operational),
        );
    }

    private function store(): string
    {
        return (string) config('cache-architecture.stores.domain', 'redis-domain');
    }
}
