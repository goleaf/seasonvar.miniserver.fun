<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\TagModerationStatus;
use App\Enums\TagSource;
use App\Enums\TagType;
use App\Enums\TagVisibility;

final readonly class TagData
{
    public function __construct(
        public string $name,
        public ?string $code,
        public TagType $type,
        public TagVisibility $visibility,
        public TagModerationStatus $moderationStatus,
        public TagSource $source,
        public ?string $slug = null,
    ) {}
}
