<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Sync;

use App\DTOs\ApiSyncCursor;
use App\Exceptions\ApiSyncCursorException;
use App\Models\ApiSyncChange;
use Illuminate\Support\Facades\Crypt;
use Throwable;

final class ApiSyncCursorCodec
{
    public function encode(ApiSyncCursor $cursor): string
    {
        return Crypt::encryptString(json_encode([
            'v' => 1,
            's' => $cursor->scope,
            'o' => $cursor->ownerId,
            'i' => $cursor->changeId,
        ], JSON_THROW_ON_ERROR));
    }

    /** @throws ApiSyncCursorException */
    public function decode(string $encoded, string $expectedScope, ?int $expectedOwnerId): ApiSyncCursor
    {
        if ($encoded === '' || mb_strlen($encoded) > 2048) {
            throw new ApiSyncCursorException(ApiSyncCursorException::INVALID);
        }

        try {
            $payload = json_decode(Crypt::decryptString($encoded), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new ApiSyncCursorException(ApiSyncCursorException::INVALID);
        }

        if (! $this->validPayload($payload)) {
            throw new ApiSyncCursorException(ApiSyncCursorException::INVALID);
        }

        if ($payload['s'] !== $expectedScope) {
            throw new ApiSyncCursorException(ApiSyncCursorException::SCOPE_MISMATCH);
        }

        if ($payload['o'] !== $expectedOwnerId) {
            throw new ApiSyncCursorException(ApiSyncCursorException::OWNER_MISMATCH);
        }

        return new ApiSyncCursor(
            scope: $payload['s'],
            ownerId: $payload['o'],
            changeId: $payload['i'],
        );
    }

    private function validPayload(mixed $payload): bool
    {
        if (! is_array($payload) || array_keys($payload) !== ['v', 's', 'o', 'i']) {
            return false;
        }

        if ($payload['v'] !== 1
            || ! is_string($payload['s'])
            || ! in_array($payload['s'], [ApiSyncChange::SCOPE_CATALOG, ApiSyncChange::SCOPE_USER], true)
            || ! is_int($payload['i'])
            || $payload['i'] < 0) {
            return false;
        }

        if ($payload['s'] === ApiSyncChange::SCOPE_CATALOG) {
            return $payload['o'] === null;
        }

        return is_int($payload['o']) && $payload['o'] > 0;
    }
}
