<?php

declare(strict_types=1);

namespace App\Services\UserPortal;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

final readonly class UserPortalIdPaginator
{
    public function __construct(private UserPortalCache $cache) {}

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $dimensions
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(
        User $user,
        string $resource,
        array $dimensions,
        Builder $query,
        int $perPage,
        string $pageName,
        bool $refresh = false,
    ): LengthAwarePaginator {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, Paginator::resolveCurrentPage($pageName));
        $snapshot = $this->cache->remember(
            $user,
            $resource,
            [...$dimensions, 'page' => $page, 'per_page' => $perPage],
            function () use ($query, $perPage, $pageName, $page): array {
                $idQuery = clone $query;
                $idQuery->setEagerLoads([]);
                $paginator = $idQuery->paginate(
                    $perPage,
                    [$idQuery->getModel()->qualifyColumn($idQuery->getModel()->getKeyName())],
                    $pageName,
                    $page,
                );

                return [
                    'ids' => $paginator->getCollection()->modelKeys(),
                    'total' => $paginator->total(),
                ];
            },
            $refresh,
        );
        $ids = collect($snapshot['ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->take($perPage)
            ->values();
        $models = $ids->isEmpty()
            ? collect()
            : (clone $query)->whereKey($ids)->get()->keyBy(fn (Model $model): int|string => $model->getKey());
        $ordered = $ids
            ->map(fn (int $id): ?Model => $models->get($id))
            ->filter()
            ->values();

        return (new LengthAwarePaginator(
            $ordered,
            max(0, (int) ($snapshot['total'] ?? 0)),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName],
        ))->withQueryString();
    }
}
