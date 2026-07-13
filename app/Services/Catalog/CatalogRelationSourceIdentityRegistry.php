<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CatalogRelationSourceIdentity;
use App\Models\Source;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Normalizer;

class CatalogRelationSourceIdentityRegistry
{
    /** @var array<int, true> */
    private array $existingSourceIds = [];

    private ?bool $identityTableAvailable = null;

    public function __construct(
        private readonly CatalogTaxonomyRegistry $taxonomies,
    ) {}

    public function resolve(
        int $sourceId,
        string $type,
        string|int|null $sourceExternalId,
        ?string $sourceUrl,
        string $fallbackCanonicalKey,
    ): string {
        $canonicalKey = $this->canonicalKey($fallbackCanonicalKey);
        $sourceKeyHash = $this->sourceKeyHash($sourceExternalId, $sourceUrl);

        if (! $this->taxonomies->supports($type)
            || $sourceId < 1
            || $canonicalKey === ''
            || $sourceKeyHash === null
            || ! $this->identityTableAvailable()
            || ! $this->sourceExists($sourceId)) {
            return $canonicalKey;
        }

        $now = now();
        $table = $this->table();
        DB::table($table)->insertOrIgnore([
            'source_id' => $sourceId,
            'relation_type' => $type,
            'source_key_hash' => $sourceKeyHash,
            'canonical_key' => $canonicalKey,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $resolved = DB::table($table)
            ->where('source_id', $sourceId)
            ->where('relation_type', $type)
            ->where('source_key_hash', $sourceKeyHash)
            ->value('canonical_key');

        return is_string($resolved) && $resolved !== '' ? $resolved : $canonicalKey;
    }

    public function sourceKeyHash(string|int|null $sourceExternalId, ?string $sourceUrl): ?string
    {
        $externalId = $this->externalId($sourceExternalId);

        if ($externalId !== null) {
            return hash('sha256', "external-id\0{$externalId}");
        }

        $normalizedUrl = $this->normalizedUrl($sourceUrl);

        return $normalizedUrl === null
            ? null
            : hash('sha256', "url\0{$normalizedUrl}");
    }

    /** @param list<string> $previousCanonicalKeys */
    public function rebind(string $type, array $previousCanonicalKeys, string $canonicalKey): int
    {
        if (! $this->taxonomies->supports($type) || ! $this->identityTableAvailable()) {
            return 0;
        }

        $canonicalKey = $this->canonicalKey($canonicalKey);
        $previousCanonicalKeys = collect($previousCanonicalKeys)
            ->map(fn (string $key): string => $this->canonicalKey($key))
            ->filter(fn (string $key): bool => $key !== '' && $key !== $canonicalKey)
            ->unique()
            ->values()
            ->all();

        if ($canonicalKey === '' || $previousCanonicalKeys === []) {
            return 0;
        }

        return DB::table($this->table())
            ->where('relation_type', $type)
            ->whereIn('canonical_key', $previousCanonicalKeys)
            ->update([
                'canonical_key' => $canonicalKey,
                'updated_at' => now(),
            ]);
    }

    public function pruneMissing(string $type, string $relationTable): int
    {
        if (! $this->taxonomies->supports($type) || ! $this->identityTableAvailable()) {
            return 0;
        }

        $modelClass = $this->taxonomies->modelClass($type);
        $expectedTable = (new $modelClass)->getTable();

        if ($relationTable !== $expectedTable) {
            return 0;
        }

        $identityTable = $this->table();

        return DB::table($identityTable)
            ->where('relation_type', $type)
            ->whereNotExists(function ($query) use ($identityTable, $relationTable): void {
                $query->selectRaw('1')
                    ->from($relationTable)
                    ->whereColumn($relationTable.'.slug', $identityTable.'.canonical_key');
            })
            ->delete();
    }

    public function pruneUnsupported(): int
    {
        if (! $this->identityTableAvailable()) {
            return 0;
        }

        return DB::table($this->table())
            ->whereNotIn('relation_type', array_keys($this->taxonomies->relations()))
            ->delete();
    }

    private function externalId(string|int|null $sourceExternalId): ?string
    {
        if ($sourceExternalId === null) {
            return null;
        }

        $externalId = (string) $sourceExternalId;
        $normalized = Normalizer::normalize($externalId, Normalizer::FORM_KC);
        $externalId = Str::of($normalized === false ? $externalId : $normalized)->trim()->toString();

        if ($externalId === ''
            || Str::length($externalId) > 255
            || preg_match('/[\x00-\x1F\x7F]/u', $externalId) === 1) {
            return null;
        }

        return $externalId;
    }

    private function normalizedUrl(?string $sourceUrl): ?string
    {
        if ($sourceUrl === null
            || Str::length($sourceUrl) > 2048
            || preg_match('/[\x00-\x20\x7F]/u', $sourceUrl) === 1
            || filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($sourceUrl);

        if (! is_array($parts)
            || Str::lower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        $host = Str::lower((string) $parts['host']);
        $port = isset($parts['port']) && (int) $parts['port'] !== 443
            ? ':'.(int) $parts['port']
            : '';
        $path = (string) ($parts['path'] ?? '');
        $path = $path !== '' ? $path : '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return "https://{$host}{$port}{$path}{$query}";
    }

    private function canonicalKey(string $canonicalKey): string
    {
        $canonicalKey = Str::of($canonicalKey)->trim()->limit(255, '')->toString();

        return preg_match('/[\x00-\x20\x7F]/u', $canonicalKey) === 1 ? '' : $canonicalKey;
    }

    private function sourceExists(int $sourceId): bool
    {
        if (isset($this->existingSourceIds[$sourceId])) {
            return true;
        }

        if (! Source::query()->whereKey($sourceId)->exists()) {
            return false;
        }

        $this->existingSourceIds[$sourceId] = true;

        return true;
    }

    private function table(): string
    {
        return (new CatalogRelationSourceIdentity)->getTable();
    }

    private function identityTableAvailable(): bool
    {
        return $this->identityTableAvailable ??= Schema::hasTable($this->table());
    }
}
