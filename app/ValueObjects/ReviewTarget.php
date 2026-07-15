<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\ReviewTargetType;

final readonly class ReviewTarget
{
    public function __construct(
        public ReviewTargetType $type,
        public int $id,
        public int $catalogTitleId,
    ) {}

    public function key(): string
    {
        return $this->type->value.':'.$this->id;
    }
}
