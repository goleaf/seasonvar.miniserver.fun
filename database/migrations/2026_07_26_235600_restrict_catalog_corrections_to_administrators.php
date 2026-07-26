<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ($this->contracts() as $code => $contract) {
                $article = DB::table('help_articles')
                    ->where('code', $code)
                    ->where('content_version', 1)
                    ->lockForUpdate()
                    ->first();

                if ($article === null) {
                    continue;
                }

                $translations = DB::table('help_article_translations')
                    ->where('help_article_id', $article->id)
                    ->whereIn('locale', array_keys($contract['replacements']))
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('locale');

                if (! $this->canReplace($article->id, $translations, $contract['replacements'], 'old', 1)) {
                    continue;
                }

                $now = now();

                foreach ($contract['replacements'] as $locale => $replacement) {
                    $translation = $translations->get($locale);
                    $body = str_replace($replacement['old'], $replacement['new'], $translation->body_markdown);
                    $searchText = $this->searchText(implode(' ', [
                        $translation->title,
                        $translation->summary,
                        $translation->keywords,
                        $body,
                    ]));

                    DB::table('help_article_translations')
                        ->where('id', $translation->id)
                        ->update([
                            'body_markdown' => $body,
                            'search_text' => $searchText,
                            'updated_at' => $now,
                        ]);

                    DB::table('help_article_revisions')->insert([
                        'public_id' => (string) Str::uuid(),
                        'help_article_id' => $article->id,
                        'locale' => $locale,
                        'revision' => 2,
                        'article_status' => $article->status,
                        'translation_published' => $translation->is_published,
                        'slug' => $translation->slug,
                        'title' => $translation->title,
                        'summary' => $translation->summary,
                        'body_markdown' => $body,
                        'keywords' => $translation->keywords,
                        'seo_title' => $translation->seo_title,
                        'seo_description' => $translation->seo_description,
                        'callout_text' => $translation->callout_text,
                        'callout_type' => $translation->callout_type,
                        'change_note' => $replacement['note'],
                        'created_at' => $now,
                    ]);
                }

                DB::table('help_articles')
                    ->where('id', $article->id)
                    ->where('content_version', 1)
                    ->update([
                        ...$contract['article_up'],
                        'content_version' => 2,
                        'updated_at' => $now,
                    ]);

                foreach ($contract['aliases_remove'] as $locale => $aliases) {
                    DB::table('help_article_aliases')
                        ->where('help_article_id', $article->id)
                        ->where('locale', $locale)
                        ->whereIn('alias', $aliases)
                        ->delete();
                }
            }
        }, attempts: 3);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach ($this->contracts() as $code => $contract) {
                $article = DB::table('help_articles')
                    ->where('code', $code)
                    ->where('content_version', 2)
                    ->lockForUpdate()
                    ->first();

                if ($article === null) {
                    continue;
                }

                $translations = DB::table('help_article_translations')
                    ->where('help_article_id', $article->id)
                    ->whereIn('locale', array_keys($contract['replacements']))
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('locale');

                if (! $this->canReplace($article->id, $translations, $contract['replacements'], 'new', 2)) {
                    continue;
                }

                $now = now();

                foreach ($contract['replacements'] as $locale => $replacement) {
                    $translation = $translations->get($locale);
                    $body = str_replace($replacement['new'], $replacement['old'], $translation->body_markdown);

                    DB::table('help_article_translations')
                        ->where('id', $translation->id)
                        ->update([
                            'body_markdown' => $body,
                            'search_text' => $this->searchText(implode(' ', [
                                $translation->title,
                                $translation->summary,
                                $translation->keywords,
                                $body,
                            ])),
                            'updated_at' => $now,
                        ]);

                    DB::table('help_article_revisions')
                        ->where('help_article_id', $article->id)
                        ->where('locale', $locale)
                        ->where('revision', 2)
                        ->where('change_note', $replacement['note'])
                        ->delete();
                }

                DB::table('help_articles')
                    ->where('id', $article->id)
                    ->where('content_version', 2)
                    ->update([
                        ...$contract['article_down'],
                        'content_version' => 1,
                        'updated_at' => $now,
                    ]);

                foreach ($contract['aliases_remove'] as $locale => $aliases) {
                    foreach ($aliases as $position => $alias) {
                        DB::table('help_article_aliases')->insertOrIgnore([
                            'help_article_id' => $article->id,
                            'locale' => $locale,
                            'alias' => $alias,
                            'normalized_alias' => $this->searchText($alias),
                            'priority' => max(0, 100 - $position),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }
        }, attempts: 3);
    }

    /**
     * @param  Collection<string, object>  $translations
     * @param  array<string, array{old: string, new: string, note: string}>  $replacements
     */
    private function canReplace(int $articleId, $translations, array $replacements, string $needle, int $expectedRevision): bool
    {
        foreach ($replacements as $locale => $replacement) {
            $translation = $translations->get($locale);

            if ($translation === null
                || ! str_contains($translation->body_markdown, $replacement[$needle])
                || (int) DB::table('help_article_revisions')
                    ->where('help_article_id', $articleId)
                    ->where('locale', $locale)
                    ->max('revision') !== $expectedRevision) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, array{
     *     article_up: array<string, mixed>,
     *     article_down: array<string, mixed>,
     *     aliases_remove: array<string, list<string>>,
     *     replacements: array<string, array{old: string, new: string, note: string}>
     * }>
     */
    private function contracts(): array
    {
        return [
            'release-calendar-and-recommendations' => [
                'article_up' => [
                    'secondary_escalation' => 'none',
                    'escalation_request_type' => null,
                ],
                'article_down' => [
                    'secondary_escalation' => 'content_request',
                    'escalation_request_type' => 'metadata_correction',
                ],
                'aliases_remove' => [],
                'replacements' => [
                    'ru' => [
                        'old' => 'Неверная существующая дата — запрос исправления метаданных. Не работающая страница календаря или действие рекомендации — технический тикет.',
                        'new' => 'Неверную существующую дату проверяют администраторы каталога. Не работающая страница календаря или действие рекомендации — технический тикет.',
                        'note' => 'Исправления каталога перенесены в административный workflow.',
                    ],
                    'en' => [
                        'old' => 'Use a metadata correction request for a wrong existing date. Use a technical ticket for a broken calendar page or recommendation action.',
                        'new' => 'Catalog administrators review a wrong existing date. Use a technical ticket for a broken calendar page or recommendation action.',
                        'note' => 'Catalog corrections moved to the administrative workflow.',
                    ],
                ],
            ],
            'content-request-or-technical-ticket' => [
                'article_up' => [],
                'article_down' => [],
                'aliases_remove' => [
                    'ru' => ['исправить описание'],
                    'en' => ['correct metadata'],
                ],
                'replacements' => [
                    'ru' => [
                        'old' => 'Он нужен для отсутствующего сериала, сезона, серии, перевода, субтитров, улучшения качества или исправления метаданных.',
                        'new' => 'Он нужен для отсутствующего сериала, сезона, серии, перевода, субтитров или улучшения качества. Исправления существующих данных выполняют администраторы каталога.',
                        'note' => 'Публичные запросы исправления данных исключены из справки.',
                    ],
                    'en' => [
                        'old' => 'It covers a missing title, season, episode, translation, subtitles, quality upgrade or metadata correction.',
                        'new' => 'It covers a missing title, season, episode, translation, subtitles or quality upgrade. Catalog administrators correct existing data.',
                        'note' => 'Public metadata correction requests were removed from help.',
                    ],
                ],
            ],
        ];
    }

    private function searchText(string $value): string
    {
        $value = Str::lower(str_replace('ё', 'е', $value));
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? '';

        return mb_substr(Str::squish($value), 0, 60_000);
    }
};
