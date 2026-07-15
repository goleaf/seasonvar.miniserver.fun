<?php

declare(strict_types=1);

namespace App\DTOs\Comments;

use App\Enums\CommentTargetType;

final readonly class CommentScopeData
{
    public function __construct(
        public CommentTargetType $type,
        public int $id,
        public string $label,
        public bool $active,
    ) {}
}
