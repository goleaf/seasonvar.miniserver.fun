<?php

declare(strict_types=1);

namespace App\Services\Pwa;

use App\Enums\HelpAudience;
use App\Http\Requests\Pwa\PwaHelpSnapshotRequest;
use App\Models\HelpArticle;
use App\Models\HelpArticleTranslation;
use App\Support\PlainText;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class PwaHelpSnapshotResponder
{
    public function response(PwaHelpSnapshotRequest $request): JsonResponse
    {
        $locale = $request->locale();
        $fallback = (string) config('app.fallback_locale', 'ru');
        $locales = array_values(array_unique([$locale, $fallback]));
        $limit = max(1, min(60, (int) config('pwa.offline.help_limit', 60)));
        $articles = HelpArticle::query()
            ->published()
            ->where('audience', HelpAudience::Everyone->value)
            ->whereHas('translations', fn ($query) => $query
                ->whereIn('locale', $locales)
                ->where('is_published', true)
                ->whereNotNull('published_at')
                ->where('published_at', '<=', now()))
            ->with(['translations' => fn ($query) => $query
                ->whereIn('locale', $locales)
                ->where('is_published', true)
                ->whereNotNull('published_at')
                ->where('published_at', '<=', now())
                ->select([
                    'id',
                    'help_article_id',
                    'locale',
                    'slug',
                    'title',
                    'summary',
                    'body_markdown',
                    'updated_at',
                ])])
            ->latest('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'updated_at']);

        $items = $articles
            ->map(function (HelpArticle $article) use ($locale, $fallback): ?array {
                $translation = $article->translations
                    ->firstWhere('locale', $locale)
                    ?? $article->translations->firstWhere('locale', $fallback);

                return $translation instanceof HelpArticleTranslation
                    ? $this->item($translation)
                    : null;
            })
            ->filter()
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'schema_version' => 1,
                'locale' => $locale,
                'generated_at' => now()->toJSON(),
                'items' => $items,
            ],
        ], 200, [
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{slug: string, title: string, summary: string, body: string, updated_at: string|null} */
    private function item(HelpArticleTranslation $translation): array
    {
        $html = Str::markdown((string) $translation->body_markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return [
            'slug' => (string) $translation->slug,
            'title' => PlainText::clean($translation->title, 220),
            'summary' => PlainText::clean($translation->summary, 500),
            'body' => PlainText::clean($html, 12_000),
            'updated_at' => $translation->updated_at?->toJSON(),
        ];
    }
}
