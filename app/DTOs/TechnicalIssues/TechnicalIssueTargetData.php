<?php

declare(strict_types=1);

namespace App\DTOs\TechnicalIssues;

use App\Enums\TechnicalIssueTargetType;

final readonly class TechnicalIssueTargetData
{
    public function __construct(
        public TechnicalIssueTargetType $type,
        public string $label,
        public ?int $catalogTitleId = null,
        public ?int $seasonId = null,
        public ?int $episodeId = null,
        public ?int $licensedMediaId = null,
        public ?int $translationId = null,
        public ?int $helpArticleId = null,
        public ?string $featureCode = null,
        public ?string $routeName = null,
        public ?string $routePath = null,
        public ?string $playerComponent = null,
        public ?string $sourceHealthCode = null,
        public ?int $knownDurationSeconds = null,
        public ?string $selectedQualityCode = null,
        public ?string $selectedAudioLanguage = null,
        public ?string $selectedSubtitleLanguage = null,
    ) {}
}
