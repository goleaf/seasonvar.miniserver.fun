<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Illuminate\Redis\RedisManager;
use Throwable;

final class RedisPersistenceInspector
{
    public function __construct(
        private readonly RedisManager $redis,
    ) {}

    /** @return array{status: string, current_save_seconds: int, last_save_age_seconds: int, changes_since_last_save: int, aof_enabled: bool, message?: string} */
    public function inspect(): array
    {
        try {
            $persistence = $this->redis
                ->connection((string) config('cache-architecture.redis_persistence.connection', 'queues'))
                ->command('info', ['persistence']);
        } catch (Throwable) {
            return $this->failure('Проверка Redis persistence недоступна.');
        }

        if (! is_array($persistence)) {
            return $this->failure('Данные Redis persistence неполны.');
        }

        return $this->summarize($persistence);
    }

    /**
     * @param  array<string, mixed>  $persistence
     * @return array{status: string, current_save_seconds: int, last_save_age_seconds: int, changes_since_last_save: int, aof_enabled: bool, message?: string}
     */
    public function summarize(array $persistence): array
    {
        if (! $this->hasRequiredFields($persistence)) {
            return $this->failure('Данные Redis persistence неполны.');
        }

        $saveRunning = $this->boundedInteger($persistence['rdb_bgsave_in_progress']) > 0;
        $currentSaveSeconds = $this->boundedInteger($persistence['rdb_current_bgsave_time_sec']);
        $lastSaveTimestamp = $this->boundedInteger($persistence['rdb_last_save_time']);
        $lastSaveAgeSeconds = max(0, now()->timestamp - $lastSaveTimestamp);
        $changesSinceLastSave = $this->boundedInteger($persistence['rdb_changes_since_last_save']);
        $aofEnabled = $this->boundedInteger($persistence['aof_enabled']) > 0;
        $metrics = [
            'current_save_seconds' => $currentSaveSeconds,
            'last_save_age_seconds' => $lastSaveAgeSeconds,
            'changes_since_last_save' => $changesSinceLastSave,
            'aof_enabled' => $aofEnabled,
        ];

        if (strtolower($persistence['rdb_last_bgsave_status']) !== 'ok') {
            return [
                'status' => 'failed',
                ...$metrics,
                'message' => 'Последнее фоновое сохранение Redis завершилось ошибкой.',
            ];
        }

        $runningWarningSeconds = max(
            1,
            (int) config('cache-architecture.redis_persistence.running_warning_seconds', 120),
        );
        $runningFailureSeconds = max(
            $runningWarningSeconds,
            (int) config('cache-architecture.redis_persistence.running_failure_seconds', 900),
        );
        $lastSaveWarningSeconds = max(
            1,
            (int) config('cache-architecture.redis_persistence.last_save_warning_seconds', 3_600),
        );

        if ($saveRunning && $currentSaveSeconds >= $runningFailureSeconds) {
            return [
                'status' => 'failed',
                ...$metrics,
                'message' => 'Фоновое сохранение Redis выполняется дольше допустимого.',
            ];
        }

        if ($saveRunning && $currentSaveSeconds >= $runningWarningSeconds) {
            return [
                'status' => 'degraded',
                ...$metrics,
                'message' => 'Фоновое сохранение Redis выполняется дольше ожидаемого.',
            ];
        }

        if (! $saveRunning && $changesSinceLastSave > 0 && $lastSaveAgeSeconds >= $lastSaveWarningSeconds) {
            return [
                'status' => 'degraded',
                ...$metrics,
                'message' => 'Изменения Redis давно не подтверждены успешным сохранением.',
            ];
        }

        return [
            'status' => 'ok',
            ...$metrics,
        ];
    }

    /** @param array<string, mixed> $persistence */
    private function hasRequiredFields(array $persistence): bool
    {
        foreach ([
            'rdb_bgsave_in_progress',
            'rdb_current_bgsave_time_sec',
            'rdb_last_save_time',
            'rdb_changes_since_last_save',
            'aof_enabled',
        ] as $field) {
            if (! array_key_exists($field, $persistence) || ! $this->isIntegerValue($persistence[$field])) {
                return false;
            }
        }

        return isset($persistence['rdb_last_bgsave_status'])
            && is_string($persistence['rdb_last_bgsave_status'])
            && $persistence['rdb_last_bgsave_status'] !== '';
    }

    private function isIntegerValue(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1);
    }

    private function boundedInteger(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        if (! is_string($value) || preg_match('/^-?\d+$/D', $value) !== 1) {
            return 0;
        }

        if (str_starts_with($value, '-')) {
            return 0;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer === false ? PHP_INT_MAX : max(0, $integer);
    }

    /** @return array{status: string, current_save_seconds: int, last_save_age_seconds: int, changes_since_last_save: int, aof_enabled: bool, message: string} */
    private function failure(string $message): array
    {
        return [
            'status' => 'failed',
            'current_save_seconds' => 0,
            'last_save_age_seconds' => 0,
            'changes_since_last_save' => 0,
            'aof_enabled' => false,
            'message' => $message,
        ];
    }
}
