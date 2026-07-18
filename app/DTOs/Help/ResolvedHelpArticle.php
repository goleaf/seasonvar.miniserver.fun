<?php

declare(strict_types=1);

namespace App\DTOs\Help;

use App\Models\HelpArticle;
use App\Models\HelpArticleTranslation;

final readonly class ResolvedHelpArticle
{
    public function __construct(
        public HelpArticle $article,
        public HelpArticleTranslation $translation,
        public string $requestedLocale,
        public bool $usesFallback,
        public bool $legacySlug,
    ) {}
}
