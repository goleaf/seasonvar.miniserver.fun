<?php

declare(strict_types=1);

namespace App\DTOs\Help;

use App\Enums\HelpArticleType;
use App\Enums\HelpAudience;

final readonly class HelpArticleData
{
    /**
     * @param  list<HelpArticleSummaryData>  $related
     * @param  list<HelpEscalationData>  $escalations
     * @param  list<array{name: string, url: string}>  $breadcrumbs
     * @param  array<string, string>  $alternates
     */
    public function __construct(
        public int $id,
        public int $translationId,
        public string $publicId,
        public string $code,
        public string $locale,
        public string $requestedLocale,
        public bool $usesFallback,
        public string $slug,
        public string $title,
        public string $summary,
        public RenderedHelpContent $content,
        public HelpArticleType $type,
        public HelpAudience $audience,
        public string $typeLabel,
        public string $categoryCode,
        public string $categoryTitle,
        public string $categoryUrl,
        public string $canonicalUrl,
        public ?string $seoTitle,
        public ?string $seoDescription,
        public ?string $calloutText,
        public ?string $calloutType,
        public ?string $publishedAt,
        public ?string $updatedAt,
        public ?string $lastReviewedLabel,
        public bool $feedbackEnabled,
        public bool $faqPresentation,
        public bool $tableOfContentsEnabled,
        public bool $indexable,
        public array $related,
        public array $escalations,
        public array $breadcrumbs,
        public array $alternates,
    ) {}
}
