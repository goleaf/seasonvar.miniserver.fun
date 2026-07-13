<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::delete(<<<'SQL'
            DELETE FROM catalog_title_aliases
            WHERE id IN (
                SELECT id
                FROM (
                    SELECT
                        id,
                        ROW_NUMBER() OVER (
                            PARTITION BY catalog_title_id, name_hash
                            ORDER BY
                                CASE type
                                    WHEN 'original' THEN 0
                                    WHEN 'alternative' THEN 1
                                    WHEN 'source-title' THEN 2
                                    ELSE 3
                                END,
                                id
                        ) AS duplicate_rank
                    FROM catalog_title_aliases
                ) AS ranked_aliases
                WHERE duplicate_rank > 1
            )
            SQL);

        $comparisonKey = static function (mixed $value): string {
            $value = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return Str::lower(str_replace(
                ['’', '‘', '`', '´'],
                "'",
                Str::squish($value),
            ));
        };
        $displayNames = static function (mixed $title, mixed $originalTitle) use ($comparisonKey): array {
            $primary = Str::squish(strip_tags((string) $title));
            $original = Str::squish(strip_tags((string) $originalTitle));

            if ($original === '' || $comparisonKey($primary) === $comparisonKey($original)) {
                return [$comparisonKey($primary)];
            }

            preg_match_all('/\//u', $primary, $separators, PREG_OFFSET_CAPTURE);

            foreach ($separators[0] ?? [] as $separator) {
                $offset = (int) ($separator[1] ?? -1);

                if ($offset < 0 || $comparisonKey(substr($primary, $offset + 1)) !== $comparisonKey($original)) {
                    continue;
                }

                $primary = Str::squish(substr($primary, 0, $offset));
                break;
            }

            return array_values(array_filter([
                $comparisonKey($primary),
                $comparisonKey($original),
            ]));
        };

        DB::table('catalog_title_aliases as aliases')
            ->join('catalog_titles as titles', 'titles.id', '=', 'aliases.catalog_title_id')
            ->select([
                'aliases.id',
                'aliases.name',
                'titles.title',
                'titles.original_title',
            ])
            ->orderBy('aliases.id')
            ->chunkById(1_000, function ($aliases) use ($comparisonKey, $displayNames): void {
                $duplicateIds = $aliases
                    ->filter(fn ($alias): bool => in_array(
                        $comparisonKey($alias->name),
                        $displayNames($alias->title, $alias->original_title),
                        true,
                    ))
                    ->pluck('id')
                    ->all();

                if ($duplicateIds !== []) {
                    DB::table('catalog_title_aliases')->whereIn('id', $duplicateIds)->delete();
                }
            }, 'aliases.id', 'id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Удалённые дубли не восстанавливаются: при изменении правила нужна forward-only миграция.
    }
};
