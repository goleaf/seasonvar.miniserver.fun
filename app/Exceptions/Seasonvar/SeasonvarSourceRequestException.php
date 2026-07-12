<?php

declare(strict_types=1);

namespace App\Exceptions\Seasonvar;

use RuntimeException;

class SeasonvarSourceRequestException extends RuntimeException
{
    private function __construct(public readonly int $status)
    {
        parent::__construct('Seasonvar вернул HTTP '.$status.'.');
    }

    public static function forStatus(int $status): self
    {
        return new self($status);
    }

    /**
     * @return array{http_status: int}
     */
    public function context(): array
    {
        return ['http_status' => $this->status];
    }
}
