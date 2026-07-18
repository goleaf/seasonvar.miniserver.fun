<?php

declare(strict_types=1);

namespace App\DTOs\Help;

final readonly class RenderedHelpContent
{
    /**
     * @param  list<array{id: string, label: string, level: int}>  $tableOfContents
     * @param  list<array{id: string, question: string, answer: string}>  $faqItems
     */
    public function __construct(
        public string $html,
        public array $tableOfContents,
        public array $faqItems = [],
    ) {}
}
