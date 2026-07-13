<?php

declare(strict_types=1);

namespace App\Services\Seasonvar\PageHandlers;

use App\DTOs\Seasonvar\SeasonvarPageHandlerDefinition;
use App\DTOs\Seasonvar\SeasonvarPageHandlerResult;
use App\Models\SourcePage;

interface SeasonvarPageHandler
{
    public function definition(): SeasonvarPageHandlerDefinition;

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function handle(SourcePage $page, string $body, ?int $importRunId = null, ?callable $progress = null): SeasonvarPageHandlerResult;
}
