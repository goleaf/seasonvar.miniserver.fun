<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Jobs\WarmCatalogTitlePage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

final class VisibleCatalogTitleCacheWarmScheduler
{
    private const REQUEST_ATTRIBUTE = 'seasonvar.visible_catalog_title_ids';

    /** @param iterable<array-key, mixed> $titleIds */
    public function capture(iterable $titleIds, Request $request): void
    {
        $ids = $this->normalize($titleIds);
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $ids);

        if ($request->headers->has('X-Livewire')) {
            $this->defer($ids);
        }
    }

    /** @return list<int> */
    public function captured(Request $request): array
    {
        $ids = $request->attributes->get(self::REQUEST_ATTRIBUTE, []);

        return is_iterable($ids) ? $this->normalize($ids) : [];
    }

    /** @param iterable<array-key, mixed> $titleIds */
    public function defer(iterable $titleIds): void
    {
        if (! $this->enabled()) {
            return;
        }

        $ids = $this->normalize($titleIds);

        if ($ids === []) {
            return;
        }

        defer(fn () => $this->schedule($ids))
            ->name('visible-title-cache-warm:'.hash('xxh3', implode(',', $ids)));
    }

    /**
     * @param  iterable<array-key, mixed>  $titleIds
     * @return list<int>
     */
    public function normalize(iterable $titleIds): array
    {
        $limit = max(1, min(96, (int) config('cache-architecture.warming.visible_titles.max_titles', 96)));
        $normalized = [];
        $seen = [];

        foreach ($titleIds as $id) {
            if (is_int($id)) {
                $titleId = $id;
            } elseif (is_string($id) && ctype_digit($id)) {
                $titleId = (int) $id;
            } else {
                continue;
            }

            if ($titleId < 1 || isset($seen[$titleId])) {
                continue;
            }

            $seen[$titleId] = true;
            $normalized[] = $titleId;

            if (count($normalized) >= $limit) {
                break;
            }
        }

        return $normalized;
    }

    /** @param list<int> $titleIds */
    public function schedule(array $titleIds): void
    {
        if (! $this->enabled()) {
            return;
        }

        foreach ($this->normalize($titleIds) as $titleId) {
            try {
                WarmCatalogTitlePage::dispatch($titleId);
            } catch (Throwable $exception) {
                Log::warning('Не удалось поставить прогрев видимой страницы тайтла в очередь.', [
                    'title_id' => $titleId,
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    private function enabled(): bool
    {
        return (bool) config('cache-architecture.warming.enabled', true)
            && (bool) config('cache-architecture.page_cache.enabled', true)
            && (bool) config('cache-architecture.page_cache.warming_enabled', true)
            && (bool) config('cache-architecture.warming.visible_titles.enabled', true);
    }
}
