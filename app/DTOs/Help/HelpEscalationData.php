<?php

declare(strict_types=1);

namespace App\DTOs\Help;

use App\Enums\HelpEscalationType;

final readonly class HelpEscalationData
{
    public function __construct(
        public HelpEscalationType $type,
        public string $label,
        public string $description,
        public ?string $url,
        public bool $requiresAuthentication,
    ) {}
}
