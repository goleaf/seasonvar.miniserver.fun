<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use ErrorException;

final class NativeCall
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws ErrorException
     */
    public static function withWarningsAsExceptions(Closure $callback): mixed
    {
        set_error_handler(
            static function (
                int $severity,
                string $message,
                string $file,
                int $line,
            ): never {
                throw new ErrorException($message, 0, $severity, $file, $line);
            },
            E_WARNING | E_USER_WARNING,
        );

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}
