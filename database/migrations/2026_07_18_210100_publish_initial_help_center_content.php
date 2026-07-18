<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var list<string> */
    private array $locales = ['ru', 'en'];

    public function up(): void
    {
        DB::transaction(function (): void {
            /** @var array<string, mixed> $content */
            $content = require database_path('data/help-center.php');
            $now = now();
            $categoryIds = [];

            foreach ($content['categories'] as $category) {
                $categoryId = DB::table('help_categories')->insertGetId([
                    'public_id' => (string) Str::uuid(),
                    'code' => $category['code'],
                    'position' => $category['position'],
                    'is_visible' => true,
                    'content_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $categoryIds[$category['code']] = $categoryId;

                foreach ($this->locales as $locale) {
                    DB::table('help_category_translations')->insert([
                        'help_category_id' => $categoryId,
                        'locale' => $locale,
                        'slug' => $category[$locale]['slug'],
                        'title' => $category[$locale]['title'],
                        'description' => $category[$locale]['description'],
                        'seo_title' => $category[$locale]['title'],
                        'seo_description' => $category[$locale]['description'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            $articleIds = [];

            foreach ($content['articles'] as $position => $article) {
                $articleId = DB::table('help_articles')->insertGetId([
                    'public_id' => (string) Str::uuid(),
                    'code' => $article['code'],
                    'help_category_id' => $categoryIds[$article['category']],
                    'type' => $article['type'],
                    'audience' => 'everyone',
                    'status' => 'published',
                    'owner_team' => $article['owner'],
                    'feature_code' => $article['feature'],
                    'primary_escalation' => $article['primary'],
                    'secondary_escalation' => $article['secondary'],
                    'escalation_issue_type' => $article['issue_type'] ?? null,
                    'escalation_request_type' => $article['request_type'] ?? null,
                    'position' => ($position + 1) * 10,
                    'editorial_priority' => $article['priority'],
                    'is_featured' => $article['featured'] ?? false,
                    'is_indexable' => true,
                    'content_version' => 1,
                    'published_at' => $now,
                    'last_reviewed_at' => $now,
                    'review_due_at' => $now->copy()->addDays(180),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $articleIds[$article['code']] = $articleId;

                foreach ($this->locales as $locale) {
                    $translation = $article[$locale];
                    DB::table('help_article_translations')->insert([
                        'help_article_id' => $articleId,
                        'locale' => $locale,
                        'slug' => $translation['slug'],
                        'title' => $translation['title'],
                        'summary' => $translation['summary'],
                        'body_markdown' => $translation['body'],
                        'search_text' => $this->searchText(implode(' ', [
                            $translation['title'],
                            $translation['summary'],
                            $translation['keywords'],
                            $translation['body'],
                        ])),
                        'keywords' => $translation['keywords'],
                        'seo_title' => $translation['title'],
                        'seo_description' => $translation['summary'],
                        'is_published' => true,
                        'published_at' => $now,
                        'links_checked_at' => $now,
                        'link_status' => 'valid',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    DB::table('help_article_revisions')->insert([
                        'public_id' => (string) Str::uuid(),
                        'help_article_id' => $articleId,
                        'locale' => $locale,
                        'revision' => 1,
                        'article_status' => 'published',
                        'translation_published' => true,
                        'slug' => $translation['slug'],
                        'title' => $translation['title'],
                        'summary' => $translation['summary'],
                        'body_markdown' => $translation['body'],
                        'keywords' => $translation['keywords'],
                        'seo_title' => $translation['title'],
                        'seo_description' => $translation['summary'],
                        'change_note' => $locale === 'ru' ? 'Первичная редакционная публикация.' : 'Initial editorial publication.',
                        'created_at' => $now,
                    ]);

                    foreach ($article['aliases'][$locale] ?? [] as $aliasPosition => $alias) {
                        DB::table('help_article_aliases')->insert([
                            'help_article_id' => $articleId,
                            'locale' => $locale,
                            'alias' => $alias,
                            'normalized_alias' => $this->searchText($alias),
                            'priority' => max(0, 100 - $aliasPosition),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }

            foreach ($content['relations'] as $source => $targets) {
                foreach ($targets as $position => $target) {
                    DB::table('help_article_relations')->insert([
                        'help_article_id' => $articleIds[$source],
                        'related_article_id' => $articleIds[$target],
                        'position' => $position,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            foreach ($content['contextual'] as $position => $contextual) {
                DB::table('help_contextual_links')->insert([
                    'feature_code' => $contextual['feature'],
                    'context_code' => $contextual['context'],
                    'help_article_id' => $articleIds[$contextual['article']],
                    'position' => $position,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }, attempts: 3);
    }

    public function down(): void
    {
        /** @var array<string, mixed> $content */
        $content = require database_path('data/help-center.php');
        DB::table('help_articles')->whereIn('code', collect($content['articles'])->pluck('code'))->delete();
        DB::table('help_categories')->whereIn('code', collect($content['categories'])->pluck('code'))->delete();
    }

    private function searchText(string $value): string
    {
        $value = Str::lower(str_replace('ё', 'е', $value));
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? '';

        return mb_substr(Str::squish($value), 0, 60_000);
    }
};
