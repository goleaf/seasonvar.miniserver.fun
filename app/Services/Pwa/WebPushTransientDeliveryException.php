<?php

declare(strict_types=1);

namespace App\Services\Pwa;

use RuntimeException;

final class WebPushTransientDeliveryException extends RuntimeException
{
    public static function providerUnavailable(): self
    {
        return new self('Web Push provider is temporarily unavailable.');
    }
}
