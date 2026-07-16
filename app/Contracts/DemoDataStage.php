<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\DemoData\DemoDataOptions;
use App\DTOs\DemoData\DemoStageReport;
use Closure;

interface DemoDataStage
{
    public function key(): string;

    public function run(DemoDataOptions $options, ?Closure $progress = null): DemoStageReport;
}
