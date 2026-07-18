<?php

declare(strict_types=1);

namespace App\Services\HelpCenter;

use App\DTOs\Help\RenderedHelpContent;
use App\Support\PlainText;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;

final readonly class HelpArticleRenderer
{
    public function __construct(private HelpLinkResolver $links) {}

    public function render(string $markdown, string $locale): RenderedHelpContent
    {
        $explicitHeadingIds = [];
        $markdown = preg_replace_callback(
            '/^(#{2,3})\s+(.+?)\s*$/mi',
            function (array $match) use (&$explicitHeadingIds): string {
                $label = $match[2];
                $id = null;

                if (preg_match('/^(.*?)\s+\{#([a-z0-9][a-z0-9_-]{1,63})\}\s*$/i', $label, $heading) === 1) {
                    $label = $heading[1];
                    $id = Str::lower($heading[2]);
                }

                $explicitHeadingIds[] = $id;

                return $match[1].' '.$label;
            },
            $markdown,
        ) ?? $markdown;
        $markdown = preg_replace_callback(
            '/\]\((help:[a-z0-9-]+|route:[a-z0-9._-]+)\)/i',
            fn (array $match): string => ']('.($this->links->resolve($match[1], $locale) ?? '#').')',
            $markdown,
        ) ?? $markdown;
        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return $this->sanitizeAndOutline($html, $explicitHeadingIds);
    }

    /** @param list<string|null> $explicitHeadingIds */
    private function sanitizeAndOutline(string $html, array $explicitHeadingIds): RenderedHelpContent
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="help-article-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->getElementById('help-article-root');

        if (! $root instanceof DOMElement) {
            return new RenderedHelpContent('', [], []);
        }

        $this->normalizeHeadings($root);
        $this->removeImages($root);
        $this->sanitizeLinks($root);
        $tableOfContents = $this->headingOutline($root, $explicitHeadingIds);
        $faqItems = $this->faqItems($root);
        $result = '';

        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child) ?: '';
        }

        return new RenderedHelpContent($result, $tableOfContents, $faqItems);
    }

    private function normalizeHeadings(DOMElement $root): void
    {
        $replacements = [];

        foreach (['h1' => 'h2', 'h4' => 'h3', 'h5' => 'h3', 'h6' => 'h3'] as $from => $to) {
            foreach ($root->getElementsByTagName($from) as $heading) {
                $replacements[] = [$heading, $to];
            }
        }

        foreach ($replacements as [$heading, $tag]) {
            if (! $heading instanceof DOMElement) {
                continue;
            }

            $replacement = $root->ownerDocument?->createElement($tag);

            if (! $replacement instanceof DOMElement) {
                continue;
            }

            while ($heading->firstChild !== null) {
                $replacement->appendChild($heading->firstChild);
            }

            $heading->parentNode?->replaceChild($replacement, $heading);
        }
    }

    private function removeImages(DOMElement $root): void
    {
        $images = [];

        foreach ($root->getElementsByTagName('img') as $image) {
            $images[] = $image;
        }

        foreach ($images as $image) {
            $alternative = PlainText::clean($image->getAttribute('alt'), 240);
            $replacement = $root->ownerDocument?->createTextNode($alternative);

            if ($replacement !== null) {
                $image->parentNode?->replaceChild($replacement, $image);
            }
        }
    }

    private function sanitizeLinks(DOMElement $root): void
    {
        foreach ($root->getElementsByTagName('a') as $link) {
            $href = trim($link->getAttribute('href'));
            $parts = parse_url($href);
            $safeInternal = str_starts_with($href, '/')
                && ! str_starts_with($href, '//')
                && ! str_contains($href, '\\')
                && preg_match('/[\x00-\x1F\x7F]/', $href) !== 1;
            $safeFragment = str_starts_with($href, '#')
                && preg_match('/^#[A-Za-z0-9][A-Za-z0-9_-]*$/D', $href) === 1;
            $safeExternal = preg_match('#^https?://#i', $href) === 1
                && is_array($parts)
                && isset($parts['host'])
                && ! isset($parts['user'], $parts['pass'])
                && ! str_contains($href, '\\')
                && mb_strlen($href) <= 1_000
                && preg_match('/[\x00-\x1F\x7F]/', $href) !== 1;
            $safe = $safeInternal || $safeFragment || $safeExternal;

            if (! $safe) {
                $link->removeAttribute('href');

                continue;
            }

            $link->removeAttribute('target');

            if (preg_match('#^https?://#i', $href) === 1) {
                $link->setAttribute('rel', 'noopener noreferrer nofollow');
            }
        }
    }

    /** @param list<string|null> $explicitHeadingIds
     * @return list<array{id: string, label: string, level: int}>
     */
    private function headingOutline(DOMElement $root, array $explicitHeadingIds): array
    {
        $items = [];
        $used = [];
        $xpath = new DOMXPath($root->ownerDocument);
        $headings = $xpath->query('.//h2 | .//h3', $root);

        if ($headings === false) {
            return [];
        }

        foreach ($headings as $index => $heading) {
            if ($heading instanceof DOMElement) {
                $label = PlainText::clean($heading->textContent, 220);
                $base = $explicitHeadingIds[$index] ?? (Str::slug($label) ?: 'section');
                $id = $base;
                $suffix = 2;

                while (isset($used[$id])) {
                    $id = $base.'-'.$suffix++;
                }

                $used[$id] = true;
                $heading->setAttribute('id', $id);
                $items[] = ['id' => $id, 'label' => $label, 'level' => (int) substr($heading->tagName, 1)];
            }
        }

        return $items;
    }

    /** @return list<array{id: string, question: string, answer: string}> */
    private function faqItems(DOMElement $root): array
    {
        $items = [];
        $current = null;

        foreach ($root->childNodes as $node) {
            if ($node instanceof DOMElement && $node->tagName === 'h2') {
                if (is_array($current)) {
                    $items[] = $current;
                }

                $current = [
                    'id' => $node->getAttribute('id'),
                    'question' => PlainText::clean($node->textContent, 220),
                    'answer' => '',
                ];

                continue;
            }

            if (is_array($current)) {
                $current['answer'] .= $root->ownerDocument?->saveHTML($node) ?: '';
            }
        }

        if (is_array($current)) {
            $items[] = $current;
        }

        return array_values(array_filter(
            $items,
            static fn (array $item): bool => $item['question'] !== '' && trim(strip_tags($item['answer'])) !== '',
        ));
    }
}
