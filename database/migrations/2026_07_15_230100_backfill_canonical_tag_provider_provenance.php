<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tags')
            ->where(function ($query): void {
                $query->whereNotNull('source_url')
                    ->orWhere('code', 'subtitle-available');
            })
            ->orderBy('id')
            ->chunkById(250, function ($tags): void {
                $now = now();
                $rows = [];
                $sourceKeysByTagId = [];

                foreach ($tags as $tag) {
                    $isSubtitle = (string) $tag->code === 'subtitle-available';
                    $sourceUrl = null;

                    if (! $isSubtitle) {
                        $sourceUrl = $this->seasonvarTagUrl((string) $tag->source_url);

                        if ($sourceUrl === null) {
                            continue;
                        }
                    }

                    $providerKey = $isSubtitle
                        ? hash('sha256', 'system:subtitle-available')
                        : hash('sha256', "url\0{$sourceUrl}");

                    $sourceKeysByTagId[(int) $tag->id] = $providerKey;
                    $normalized = is_string($tag->normalized_name) && $tag->normalized_name !== ''
                        ? $tag->normalized_name
                        : $this->normalized((string) $tag->name);
                    $rows[] = [
                        'provider' => 'seasonvar',
                        'provider_key' => $providerKey,
                        'tag_id' => (int) $tag->id,
                        'raw_label' => (string) $tag->name,
                        'normalized_name' => $normalized,
                        'normalized_name_hash' => hash('sha256', $normalized),
                        'source_url' => $sourceUrl,
                        'status' => (string) $tag->moderation_status === 'approved' ? 'approved' : 'pending',
                        'confidence' => 100,
                        'last_seen_at' => $tag->updated_at ?? $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('tag_provider_mappings')->insertOrIgnore($rows);
                }

                if ($sourceKeysByTagId === []) {
                    return;
                }

                $eligibleMappings = DB::table('tag_provider_mappings')
                    ->where('provider', 'seasonvar')
                    ->whereIn('provider_key', array_values($sourceKeysByTagId))
                    ->whereIn('status', ['pending', 'approved'])
                    ->whereNotNull('tag_id')
                    ->get(['tag_id', 'provider_key'])
                    ->filter(fn (object $mapping): bool => ($sourceKeysByTagId[(int) $mapping->tag_id] ?? null) === $mapping->provider_key)
                    ->mapWithKeys(fn (object $mapping): array => [(int) $mapping->tag_id => (string) $mapping->provider_key])
                    ->all();

                if ($eligibleMappings === []) {
                    return;
                }

                DB::table('catalog_title_tag')
                    ->join('catalog_titles', 'catalog_titles.id', '=', 'catalog_title_tag.catalog_title_id')
                    ->whereIn('catalog_title_tag.tag_id', array_keys($eligibleMappings))
                    ->select([
                        'catalog_title_tag.catalog_title_id',
                        'catalog_title_tag.tag_id',
                        'catalog_titles.source_id',
                        'catalog_titles.created_at',
                        'catalog_titles.updated_at',
                    ])
                    ->orderBy('catalog_title_tag.catalog_title_id')
                    ->orderBy('catalog_title_tag.tag_id')
                    ->chunk(1_000, function ($assignments) use ($eligibleMappings): void {
                        $now = now();
                        $provenance = $assignments->map(fn (object $assignment): array => [
                            'catalog_title_id' => (int) $assignment->catalog_title_id,
                            'tag_id' => (int) $assignment->tag_id,
                            'source' => 'seasonvar',
                            'provider' => 'seasonvar',
                            'source_id' => $assignment->source_id === null ? null : (int) $assignment->source_id,
                            'source_key' => $eligibleMappings[(int) $assignment->tag_id],
                            'is_current' => true,
                            'first_seen_at' => $assignment->created_at ?? $now,
                            'last_seen_at' => $assignment->updated_at ?? $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])->all();

                        if ($provenance !== []) {
                            DB::table('catalog_title_tag_sources')->insertOrIgnore($provenance);
                        }
                    });
            });
    }

    public function down(): void
    {
        // Deliberately preserve mappings and provenance: later imports may have updated these rows.
    }

    private function seasonvarTagUrl(string $url): ?string
    {
        $url = trim($url);

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        if ($url === ''
            || strlen($url) > 2_048
            || preg_match('/[\x00-\x20\x7F]/u', $url) === 1
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return null;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if (! in_array($host, ['seasonvar.ru', 'www.seasonvar.ru'], true)) {
            return null;
        }

        $path = $this->normalizedPath((string) ($parts['path'] ?? '/'));

        if ($path === null
            || preg_match('~^/tag(?:/|$)~u', strtolower(rawurldecode($path))) !== 1
            || str_contains(strtolower($path), '.html/')) {
            return null;
        }

        $query = $this->identityQuery(isset($parts['query']) ? (string) $parts['query'] : null);

        return 'https://seasonvar.ru'.$path.$query;
    }

    private function normalizedPath(string $path): ?string
    {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $path) === 1) {
            return null;
        }

        $path = preg_replace('~/+~', '/', '/'.ltrim($path, '/')) ?? '/';
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }

            $segment = str_ireplace(
                ['%21', '%24', '%26', '%27', '%28', '%29', '%2A', '%2B', '%2C', '%3B', '%3D', '%3A', '%40'],
                ['!', '$', '&', "'", '(', ')', '*', '+', ',', ';', '=', ':', '@'],
                rawurlencode(rawurldecode($segment)),
            );

            if ($segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    private function identityQuery(?string $query): string
    {
        if ($query === null || $query === '') {
            return '';
        }

        $identity = [];

        foreach (explode('&', $query) as $pair) {
            [$rawKey, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            $key = strtolower(rawurldecode($rawKey));
            $value = rawurldecode($rawValue);

            if (! in_array($key, ['mod', 'mode', 'page', 'time'], true)
                || preg_match('/\A[\pL\pN_-]{1,64}\z/u', $value) !== 1) {
                continue;
            }

            $identity[$key] = $value;
        }

        if ($identity === []) {
            return '';
        }

        ksort($identity);

        return '?'.http_build_query($identity, '', '&', PHP_QUERY_RFC3986);
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
