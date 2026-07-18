<?php

declare(strict_types=1);

namespace App\Services\HelpCenter;

use App\DTOs\Help\RenderedHelpContent;
use App\Enums\HelpAudience;
use App\Enums\HelpPublicationStatus;
use App\Models\HelpArticle;
use App\Models\HelpArticleTranslation;

final readonly class HelpArticleContentCache
{
    public function __construct(
        private HelpArticleRenderer $renderer,
        private HelpSnapshotCache $cache,
    ) {}

    public function render(HelpArticle $article, HelpArticleTranslation $translation): RenderedHelpContent
    {
        if ($article->status !== HelpPublicationStatus::Published
            || ! $translation->is_published
            || $article->audience === HelpAudience::Staff) {
            return $this->renderer->render($translation->body_markdown, $translation->locale);
        }

        $snapshot = $this->cache->remember('article-content', [
            'article' => $article->public_id,
            'locale' => $translation->locale,
            'audience' => $article->audience->value,
            'version' => $article->content_version,
            'translation_version' => $translation->updated_at?->getTimestamp(),
            'presentation' => 1,
        ], function () use ($translation): array {
            $rendered = $this->renderer->render($translation->body_markdown, $translation->locale);

            return [
                'html' => $rendered->html,
                'table_of_contents' => $rendered->tableOfContents,
                'faq_items' => $rendered->faqItems,
            ];
        });

        return new RenderedHelpContent(
            html: is_string($snapshot['html'] ?? null) ? $snapshot['html'] : '',
            tableOfContents: is_array($snapshot['table_of_contents'] ?? null) ? $snapshot['table_of_contents'] : [],
            faqItems: is_array($snapshot['faq_items'] ?? null) ? $snapshot['faq_items'] : [],
        );
    }
}
