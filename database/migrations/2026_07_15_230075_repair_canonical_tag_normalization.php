<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumns('tags', ['normalized_name', 'normalized_name_hash', 'merged_into_id'])) {
            return;
        }

        DB::table('tags')
            ->whereNull('merged_into_id')
            ->orderBy('id')
            ->chunkById(250, function ($tags): void {
                foreach ($tags as $tag) {
                    $normalized = $this->normalized((string) $tag->name);

                    DB::table('tags')->where('id', $tag->id)->update([
                        'normalized_name' => $normalized,
                        'normalized_name_hash' => hash('sha256', $normalized),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // This data-only repair is intentionally irreversible.
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
