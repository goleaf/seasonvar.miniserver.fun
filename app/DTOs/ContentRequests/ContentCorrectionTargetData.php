<?php

declare(strict_types=1);

namespace App\DTOs\ContentRequests;

use App\Enums\ContentCorrectionField;
use App\Enums\ContentRequestType;

final readonly class ContentCorrectionTargetData
{
    public function __construct(
        public ContentCorrectionField $field,
        public ContentRequestType $type,
        public string $storedField,
        public string $targetKey,
        public string $currentValue,
        public string $proposedValue,
        public ?int $seasonId = null,
        public ?int $episodeId = null,
    ) {}
}
