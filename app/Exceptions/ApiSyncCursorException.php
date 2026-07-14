<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ApiSyncCursorException extends RuntimeException
{
    public const string INVALID = 'invalid';

    public const string EXPIRED = 'expired';

    public const string OWNER_MISMATCH = 'owner_mismatch';

    public const string SCOPE_MISMATCH = 'scope_mismatch';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Некорректный курсор синхронизации.');
    }

    /** @return array<never, never> */
    public function context(): array
    {
        return [];
    }
}
