<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\CommentTargetType;

final readonly class CommentTarget
{
    public function __construct(
        public CommentTargetType $type,
        public int $id,
        public ?int $catalogTitleId,
        public string $label,
        public string $canonicalUrl,
        public ?int $seasonId = null,
        public ?int $episodeId = null,
    ) {}

    public function key(): string
    {
        return $this->type->value.':'.$this->id;
    }
}
