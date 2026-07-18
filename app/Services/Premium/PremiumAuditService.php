<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\Enums\PremiumAuditAction;
use App\Models\PremiumAuditEvent;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PremiumAuditService
{
    /**
     * @param  array<string, bool|int|string|null>  $context
     */
    public function record(
        PremiumAuditAction $action,
        string $resourceType,
        ?string $resourcePublicId,
        string $idempotencyIdentity,
        ?User $user = null,
        ?User $actor = null,
        array $context = [],
    ): PremiumAuditEvent {
        if (preg_match('/\A[a-z][a-z0-9_]{1,31}\z/', $resourceType) !== 1) {
            throw new InvalidArgumentException('Некорректный тип ресурса premium-аудита.');
        }

        foreach ($context as $key => $value) {
            if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $key) !== 1
                || (is_string($value) && mb_strlen($value) > 191)) {
                throw new InvalidArgumentException('Premium-аудит содержит неподдерживаемый контекст.');
            }
        }

        return PremiumAuditEvent::query()->firstOrCreate(
            ['idempotency_key' => hash('sha256', $idempotencyIdentity)],
            [
                'public_id' => (string) Str::uuid(),
                'actor_id' => $actor?->id,
                'user_id' => $user?->id,
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_public_id' => $resourcePublicId,
                'context' => $context,
                'occurred_at' => now(),
            ],
        );
    }
}
