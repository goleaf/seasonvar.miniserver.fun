<?php

declare(strict_types=1);

namespace App\Exceptions\Crawler;

use RuntimeException;

final class RemoteResponseTooLargeException extends RuntimeException
{
    public function __construct(public readonly int $maximumBytes)
    {
        parent::__construct('Ответ внешнего источника превышает разрешённый размер.');
    }
}
