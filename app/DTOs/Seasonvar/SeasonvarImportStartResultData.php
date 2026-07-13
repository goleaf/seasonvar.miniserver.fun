<?php

declare(strict_types=1);

namespace App\DTOs\Seasonvar;

use App\Models\SeasonvarImportRun;

final readonly class SeasonvarImportStartResultData
{
    public function __construct(
        public SeasonvarImportRun $run,
        public bool $created,
    ) {}
}
