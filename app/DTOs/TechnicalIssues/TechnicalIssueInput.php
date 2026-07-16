<?php

declare(strict_types=1);

namespace App\DTOs\TechnicalIssues;

use App\Enums\TechnicalIssueType;

final readonly class TechnicalIssueInput
{
    public function __construct(
        public TechnicalIssueType $type,
        public string $contextToken,
        public string $featureCode,
        public ?string $summary,
        public ?string $expectedBehavior,
        public ?string $actualBehavior,
        public ?string $reproductionSteps,
        public ?int $playbackPositionSeconds,
        public ?string $audioLanguage,
        public ?string $subtitleLanguage,
        public ?string $qualityCode,
        public ?string $publicErrorCode,
        public bool $diagnosticsConsent,
        public ?string $browserFamily,
        public ?int $browserMajor,
        public ?string $operatingSystem,
        public ?string $deviceCategory,
        public ?int $viewportWidth,
        public ?int $viewportHeight,
        public ?string $timezone,
        public ?bool $networkOnline,
        public string $submissionToken,
    ) {}
}
