<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->reconcileExactDuplicates();

        $duplicate = DB::table('tags')
            ->select('normalized_name_hash')
            ->whereNotNull('normalized_name_hash')
            ->groupBy('normalized_name_hash')
            ->havingRaw('count(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException('Canonical tag-name uniqueness cannot be enabled until duplicate tags are reviewed and merged.');
        }

        Schema::table('tags', function (Blueprint $table): void {
            $table->dropIndex('tags_normalized_name_hash_idx');
            $table->unique('normalized_name_hash', 'tags_normalized_name_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table): void {
            $table->dropUnique('tags_normalized_name_hash_unique');
            $table->index('normalized_name_hash', 'tags_normalized_name_hash_idx');
        });
    }

    private function reconcileExactDuplicates(): void
    {
        $hashes = DB::table('tags')
            ->select('normalized_name_hash')
            ->whereNotNull('normalized_name_hash')
            ->groupBy('normalized_name_hash')
            ->havingRaw('count(*) > 1')
            ->orderBy('normalized_name_hash')
            ->pluck('normalized_name_hash');

        foreach ($hashes as $hash) {
            if (! is_string($hash) || $hash === '') {
                continue;
            }

            DB::transaction(function () use ($hash): void {
                $tags = DB::table('tags')
                    ->where('normalized_name_hash', $hash)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($tags->count() < 2) {
                    return;
                }

                $assignmentCounts = DB::table('catalog_title_tag')
                    ->whereIn('tag_id', $tags->pluck('id'))
                    ->select('tag_id')
                    ->selectRaw('count(*) as aggregate')
                    ->groupBy('tag_id')
                    ->pluck('aggregate', 'tag_id');
                $ordered = $tags->sort(function (object $left, object $right) use ($assignmentCounts): int {
                    $leftRank = $this->canonicalRank($left, (int) ($assignmentCounts[$left->id] ?? 0));
                    $rightRank = $this->canonicalRank($right, (int) ($assignmentCounts[$right->id] ?? 0));

                    return $leftRank <=> $rightRank;
                })->values();
                $target = $ordered->first();

                if (! is_object($target)) {
                    return;
                }

                if ($target->merged_into_id !== null) {
                    DB::table('tags')->whereIn('id', $ordered->pluck('id'))->update([
                        'normalized_name_hash' => null,
                        'updated_at' => now(),
                    ]);

                    return;
                }

                foreach ($ordered->slice(1) as $source) {
                    if ($source->merged_into_id !== null) {
                        DB::table('tags')->where('id', $source->id)->update([
                            'normalized_name_hash' => null,
                            'updated_at' => now(),
                        ]);

                        continue;
                    }

                    $this->mergeExactDuplicate($source, $target);
                }
            }, attempts: 3);
        }
    }

    /** @return array<int, int> */
    private function canonicalRank(object $tag, int $assignmentCount): array
    {
        $typeRank = match ((string) $tag->type) {
            'system' => 0,
            'editorial' => 1,
            'imported' => 2,
            default => 3,
        };
        $moderationRank = match ((string) $tag->moderation_status) {
            'approved' => 0,
            'pending' => 1,
            default => 2,
        };

        return [
            $tag->merged_into_id === null ? 0 : 1,
            $tag->archived_at === null ? 0 : 1,
            $typeRank,
            $moderationRank,
            (string) $tag->visibility === 'public' ? 0 : 1,
            -$assignmentCount,
            (int) $tag->id,
        ];
    }

    private function mergeExactDuplicate(object $source, object $target): void
    {
        if ((int) $source->id === (int) $target->id) {
            return;
        }

        $affectedTitleIds = DB::table('catalog_title_tag')
            ->where('tag_id', $source->id)
            ->pluck('catalog_title_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($affectedTitleIds as $titleId) {
            DB::table('catalog_title_tag')->insertOrIgnore([
                'catalog_title_id' => $titleId,
                'tag_id' => (int) $target->id,
            ]);
        }

        DB::table('catalog_title_tag')->where('tag_id', $source->id)->delete();
        $this->moveProvenance((int) $source->id, (int) $target->id);
        $this->moveTranslations($source, $target);
        DB::table('tag_aliases')->where('tag_id', $source->id)->update([
            'tag_id' => (int) $target->id,
            'updated_at' => now(),
        ]);
        DB::table('tag_slugs')->where('tag_id', $source->id)->update([
            'tag_id' => (int) $target->id,
            'updated_at' => now(),
        ]);
        DB::table('tag_slugs')->insertOrIgnore([
            'tag_id' => (int) $target->id,
            'slug' => (string) $source->slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tag_provider_mappings')->where('tag_id', $source->id)->update([
            'tag_id' => (int) $target->id,
            'updated_at' => now(),
        ]);
        $this->moveSynonyms((int) $source->id, (int) $target->id);

        DB::table('tag_merge_events')->insert([
            'source_tag_id' => (int) $source->id,
            'target_tag_id' => (int) $target->id,
            'actor_id' => null,
            'snapshot' => json_encode([
                'reason' => 'exact_normalized_legacy_duplicate',
                'source' => (array) $source,
                'target_public_id' => (string) $target->public_id,
                'affected_title_ids' => array_values(array_unique($affectedTitleIds)),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'occurred_at' => now(),
        ]);
        DB::table('tags')->where('id', $source->id)->update([
            'visibility' => 'internal',
            'moderation_status' => 'merged',
            'normalized_name_hash' => null,
            'merged_into_id' => (int) $target->id,
            'archived_at' => now(),
            'content_version' => max(1, (int) $source->content_version) + 1,
            'updated_at' => now(),
        ]);
        DB::table('tags')->where('id', $target->id)->increment('content_version');

        if ($affectedTitleIds !== [] && Schema::hasTable('catalog_title_recommendations')) {
            DB::table('catalog_title_recommendations')
                ->whereIn('catalog_title_id', $affectedTitleIds)
                ->orWhereIn('recommended_title_id', $affectedTitleIds)
                ->delete();
        }
    }

    private function moveProvenance(int $sourceId, int $targetId): void
    {
        $rows = DB::table('catalog_title_tag_sources')
            ->where('tag_id', $sourceId)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $identity = [
                'catalog_title_id' => (int) $row->catalog_title_id,
                'tag_id' => $targetId,
                'source' => (string) $row->source,
                'source_key' => (string) $row->source_key,
            ];
            $existing = DB::table('catalog_title_tag_sources')->where($identity)->first();

            if ($existing === null) {
                DB::table('catalog_title_tag_sources')->where('id', $row->id)->update([
                    'tag_id' => $targetId,
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('catalog_title_tag_sources')->where('id', $existing->id)->update([
                'provider' => $existing->provider ?? $row->provider,
                'source_id' => $existing->source_id ?? $row->source_id,
                'is_current' => (bool) $existing->is_current || (bool) $row->is_current,
                'first_seen_at' => min((string) $existing->first_seen_at, (string) $row->first_seen_at),
                'last_seen_at' => max((string) $existing->last_seen_at, (string) $row->last_seen_at),
                'updated_at' => now(),
            ]);
            DB::table('catalog_title_tag_sources')->where('id', $row->id)->delete();
        }
    }

    private function moveTranslations(object $source, object $target): void
    {
        $translations = DB::table('tag_translations')
            ->where('tag_id', $source->id)
            ->orderBy('id')
            ->get();

        foreach ($translations as $translation) {
            $existing = DB::table('tag_translations')
                ->where('tag_id', $target->id)
                ->where('locale', $translation->locale)
                ->first();

            if ($existing === null) {
                DB::table('tag_translations')->where('id', $translation->id)->update([
                    'tag_id' => (int) $target->id,
                    'updated_at' => now(),
                ]);

                continue;
            }

            $updates = [];

            foreach (['short_description', 'description', 'seo_title', 'seo_description'] as $field) {
                if (($existing->{$field} === null || $existing->{$field} === '')
                    && $translation->{$field} !== null && $translation->{$field} !== '') {
                    $updates[$field] = $translation->{$field};
                }
            }

            if ($updates !== []) {
                $updates['updated_at'] = now();
                DB::table('tag_translations')->where('id', $existing->id)->update($updates);
            }

            if ((string) $existing->label !== (string) $translation->label) {
                $this->preserveAlias(
                    (int) $source->id,
                    (int) $target->id,
                    (string) $target->normalized_name_hash,
                    (string) $translation->label,
                    (string) $translation->locale,
                );
            }

            DB::table('tag_translations')->where('id', $translation->id)->delete();
        }
    }

    private function moveSynonyms(int $sourceId, int $targetId): void
    {
        $synonyms = DB::table('tag_synonyms')
            ->where('tag_id', $sourceId)
            ->orWhere('related_tag_id', $sourceId)
            ->orderBy('id')
            ->get();

        foreach ($synonyms as $synonym) {
            $tagId = (int) $synonym->tag_id === $sourceId ? $targetId : (int) $synonym->tag_id;
            $relatedId = (int) $synonym->related_tag_id === $sourceId ? $targetId : (int) $synonym->related_tag_id;

            if ($tagId !== $relatedId) {
                $existing = DB::table('tag_synonyms')
                    ->where('relationship', (string) $synonym->relationship)
                    ->where(function ($query) use ($tagId, $relatedId, $synonym): void {
                        $query->where(fn ($query) => $query
                            ->where('tag_id', $tagId)
                            ->where('related_tag_id', $relatedId))
                            ->orWhere(fn ($query) => $query
                                ->where('tag_id', $relatedId)
                                ->where('related_tag_id', $tagId)
                                ->when(! (bool) $synonym->is_bidirectional, fn ($query) => $query->where('is_bidirectional', true)));
                    })
                    ->first();

                if ($existing === null) {
                    DB::table('tag_synonyms')->insert([
                        'tag_id' => $tagId,
                        'related_tag_id' => $relatedId,
                        'relationship' => (string) $synonym->relationship,
                        'is_bidirectional' => (bool) $synonym->is_bidirectional,
                        'priority' => (int) $synonym->priority,
                        'created_at' => $synonym->created_at,
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('tag_synonyms')->where('id', $existing->id)->update([
                        'is_bidirectional' => (bool) $existing->is_bidirectional || (bool) $synonym->is_bidirectional,
                        'priority' => min((int) $existing->priority, (int) $synonym->priority),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::table('tag_synonyms')->where('id', $synonym->id)->delete();
        }
    }

    private function preserveAlias(
        int $sourceId,
        int $targetId,
        string $targetNameHash,
        string $name,
        string $locale,
    ): void {
        $normalized = $this->normalized($name);

        if ($normalized === '') {
            return;
        }

        $hash = hash('sha256', $normalized);

        if (hash_equals($targetNameHash, $hash)
            || DB::table('tags')
                ->whereNotIn('id', [$sourceId, $targetId])
                ->where('normalized_name_hash', $hash)
                ->exists()) {
            return;
        }

        $existing = DB::table('tag_aliases')
            ->where('locale', $locale)
            ->where('normalized_name_hash', $hash)
            ->first();

        if ($existing !== null) {
            if (in_array((int) $existing->tag_id, [$sourceId, $targetId], true)) {
                DB::table('tag_aliases')->where('id', $existing->id)->update([
                    'tag_id' => $targetId,
                    'moderation_status' => 'approved',
                    'updated_at' => now(),
                ]);
            }

            return;
        }

        DB::table('tag_aliases')->insert([
            'public_id' => (string) Str::uuid(),
            'tag_id' => $targetId,
            'locale' => $locale,
            'name' => $name,
            'normalized_name' => $normalized,
            'normalized_name_hash' => $hash,
            'slug' => null,
            'source' => 'former_label',
            'source_key' => null,
            'moderation_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function normalized(string $value): string
    {
        $normalized = class_exists(Normalizer::class)
            ? Normalizer::normalize($value, Normalizer::FORM_C)
            : $value;
        $value = $normalized === false ? $value : $normalized;
        $value = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}"], ' ', $value);
        $value = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $value) ?? '';
        $value = preg_replace('/^\s*#+\s*/u', '', $value) ?? $value;
        $value = Str::squish($value);
        $normalized = class_exists(Normalizer::class)
            ? Normalizer::normalize($value, Normalizer::FORM_KC)
            : $value;
        $value = $normalized === false ? $value : $normalized;
        $value = preg_replace('/\p{Pd}+/u', '-', $value) ?? $value;
        $value = preg_replace('/\s*[-‐‑‒–—―]\s*/u', '-', $value) ?? $value;
        $value = preg_replace('/\s*([:;,\/|])\s*/u', '$1', $value) ?? $value;

        return mb_strtolower(Str::squish($value));
    }
};
