<?php

declare(strict_types=1);

namespace App\Support;

final class DeterministicUuid
{
    public static function from(string $namespace, string $value): string
    {
        $hex = substr(hash('sha256', $namespace."\0".$value), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);
    }
}
