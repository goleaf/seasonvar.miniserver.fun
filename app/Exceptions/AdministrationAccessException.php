<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class AdministrationAccessException extends RuntimeException
{
    public function __construct(public readonly string $translationKey)
    {
        parent::__construct(__($translationKey));
    }
}
