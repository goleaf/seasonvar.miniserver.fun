<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\User;
use Illuminate\Session\Store;

final class CatalogRecommendationRepeatSuppressor
{
    private const SESSION_KEY = 'catalog.recommendations.recent.v1';

    public function __construct(private readonly Store $session) {}

    /** @return list<int> */
    public function recentIds(?User $user): array
    {
        $rows = $this->rows($user);

        return collect($rows)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /** @param iterable<int, int|string> $titleIds */
    public function remember(?User $user, iterable $titleIds): void
    {
        $now = now()->timestamp;
        $newRows = collect($titleIds)
            ->filter(fn (int|string $id): bool => is_int($id) || ctype_digit($id))
            ->map(fn (int|string $id): array => ['id' => (int) $id, 'shown_at' => $now])
            ->filter(fn (array $row): bool => $row['id'] > 0);
        $rows = $newRows
            ->concat($this->rows($user))
            ->unique('id')
            ->take(max(1, (int) config('recommendations.repeat_suppression.max_ids', 96)))
            ->values()
            ->all();

        $all = $this->session->get(self::SESSION_KEY, []);
        $all = is_array($all) ? $all : [];
        $all[$this->scope($user)] = $rows;
        $this->session->put(self::SESSION_KEY, $all);
    }

    /** @return list<array{id: int, shown_at: int}> */
    private function rows(?User $user): array
    {
        $all = $this->session->get(self::SESSION_KEY, []);
        $rows = is_array($all) ? ($all[$this->scope($user)] ?? []) : [];
        $cutoff = now()->subDays(max(1, (int) config('recommendations.repeat_suppression.days', 7)))->timestamp;

        return collect(is_array($rows) ? $rows : [])
            ->filter(fn (mixed $row): bool => is_array($row)
                && is_numeric($row['id'] ?? null)
                && is_numeric($row['shown_at'] ?? null)
                && (int) $row['shown_at'] >= $cutoff)
            ->map(fn (array $row): array => ['id' => (int) $row['id'], 'shown_at' => (int) $row['shown_at']])
            ->values()
            ->all();
    }

    private function scope(?User $user): string
    {
        return $user === null ? 'guest' : 'user:'.$user->id;
    }
}
