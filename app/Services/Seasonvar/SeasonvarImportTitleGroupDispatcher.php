<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Enums\SeasonvarImportStatus;
use App\Enums\SeasonvarImportTitleGroupStatus;
use App\Enums\SeasonvarPageType;
use App\Enums\SeasonvarPreparedPageStatus;
use App\Jobs\FinalizeSeasonvarImportTitleGroup;
use App\Jobs\PrepareSeasonvarImportTitlePage;
use App\Models\CatalogTitle;
use App\Models\SeasonvarImportPreparedPage;
use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;
use App\Models\SourcePage;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class SeasonvarImportTitleGroupDispatcher
{
    public function __construct(
        private readonly SeasonvarUrl $seasonvarUrl,
        private readonly SeasonvarImportGroupKey $groupKeys,
    ) {}

    public function start(CatalogTitle $title, string $queue): SeasonvarImportTitleGroup
    {
        $sourceUrl = $this->normalizedSerialUrl((string) $title->source_url);

        if ($sourceUrl === null) {
            throw new InvalidArgumentException('У тайтла нет допустимой ссылки Seasonvar для обновления.');
        }

        $run = SeasonvarImportRun::query()->create([
            'mode' => 'url',
            'execution_mode' => 'queue',
            'status' => SeasonvarImportStatus::Running->value,
            'argument' => $sourceUrl,
            'force' => true,
            'forever' => false,
            'selected' => 0,
            'last_heartbeat_at' => now(),
            'started_at' => now(),
            'summary' => [
                'catalog_title_id' => $title->id,
                'provider' => 'seasonvar',
                'queue' => $queue,
            ],
        ]);
        $group = SeasonvarImportTitleGroup::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'catalog_title_id' => $title->id,
            'group_key_hash' => hash('sha256', $this->groupKeys->forUrl(
                $sourceUrl,
                $this->seasonvarUrl->hash($sourceUrl),
            )),
            'queue_name' => $queue,
            'status' => SeasonvarImportTitleGroupStatus::Discovering,
            'started_at' => now(),
        ]);
        $urls = $title->seasons()
            ->whereNotNull('source_url')
            ->pluck('source_url')
            ->prepend($sourceUrl)
            ->all();

        $this->addUrls($group, $urls);
        $group->update(['status' => SeasonvarImportTitleGroupStatus::Running]);
        FinalizeSeasonvarImportTitleGroup::dispatch($group->id)
            ->onConnection((string) config('seasonvar.queue.connection', 'redis'))
            ->onQueue($queue)
            ->delay(now()->addSeconds($this->finalizerDelaySeconds()))
            ->afterCommit();

        return $group->fresh();
    }

    /**
     * @param  list<string>  $urls
     */
    public function addUrls(SeasonvarImportTitleGroup $group, array $urls): int
    {
        $group->loadMissing(['catalogTitle.source', 'run', 'preparedPages.sourcePage']);
        $sourceId = $group->catalogTitle?->source_id
            ?? $group->preparedPages->first()?->sourcePage?->source_id;

        if ($sourceId === null) {
            throw new InvalidArgumentException('Для группы Seasonvar не определён источник каталога.');
        }

        $created = 0;

        foreach ($this->normalizedUrls($urls) as $url) {
            if (! $this->belongsToGroup($group, $url)) {
                continue;
            }

            $createdForUrl = $this->attachUrl($group, (int) $sourceId, $url);

            $created += $createdForUrl ? 1 : 0;
        }

        return $created;
    }

    private function belongsToGroup(SeasonvarImportTitleGroup $group, string $url): bool
    {
        $groupKeyHash = hash('sha256', $this->groupKeys->forUrl(
            $url,
            $this->seasonvarUrl->hash($url),
        ));

        return hash_equals($group->group_key_hash, $groupKeyHash);
    }

    public function adoptPage(
        SeasonvarImportRun $run,
        SourcePage $page,
        string $queue,
        ?CatalogTitle $title = null,
    ): SeasonvarImportPreparedPage {
        $normalizedUrl = $this->normalizedSerialUrl($page->url);

        if ($normalizedUrl === null) {
            throw new InvalidArgumentException('Страница не принадлежит сериалам Seasonvar.');
        }

        $normalizedHash = $this->seasonvarUrl->hash($normalizedUrl);

        if ($page->url !== $normalizedUrl || $page->url_hash !== $normalizedHash) {
            $page->update([
                'url' => $normalizedUrl,
                'url_hash' => $normalizedHash,
                'page_type' => SeasonvarPageType::Serial->value,
            ]);
        }

        $title ??= $this->catalogTitleForPage($page);
        $groupKeyHash = hash('sha256', $this->groupKeys->forUrl($page->url, $page->url_hash));
        $group = SeasonvarImportTitleGroup::query()->firstOrCreate(
            [
                'seasonvar_import_run_id' => $run->id,
                'group_key_hash' => $groupKeyHash,
            ],
            [
                'catalog_title_id' => $title?->id,
                'queue_name' => $queue,
                'status' => SeasonvarImportTitleGroupStatus::Running,
                'started_at' => now(),
            ],
        );

        if ($group->catalog_title_id === null && $title !== null) {
            $group->update(['catalog_title_id' => $title->id]);
        }

        $this->attachUrl($group, (int) $page->source_id, $page->url);

        if ($group->wasRecentlyCreated) {
            FinalizeSeasonvarImportTitleGroup::dispatch($group->id)
                ->onConnection((string) config('seasonvar.queue.connection', 'redis'))
                ->onQueue($queue)
                ->delay(now()->addSeconds($this->finalizerDelaySeconds()))
                ->afterCommit();
        }

        return $group->preparedPages()->where('source_page_id', $page->id)->firstOrFail();
    }

    private function catalogTitleForPage(SourcePage $page): ?CatalogTitle
    {
        return CatalogTitle::query()
            ->where('source_id', $page->source_id)
            ->where(function ($query) use ($page): void {
                $query->where('source_page_id', $page->id)
                    ->orWhere('source_url_hash', $page->url_hash)
                    ->orWhereHas('seasons', fn ($query) => $query->where('source_url_hash', $page->url_hash));
            })
            ->orderBy('id')
            ->first();
    }

    private function attachUrl(SeasonvarImportTitleGroup $group, int $sourceId, string $url): bool
    {
        return DB::transaction(function () use ($group, $sourceId, $url): bool {
            $now = now();
            $urlHash = $this->seasonvarUrl->hash($url);
            SourcePage::query()->insertOrIgnore([
                'source_id' => $sourceId,
                'url' => $url,
                'url_hash' => $urlHash,
                'page_type' => SeasonvarPageType::Serial->value,
                'parse_status' => 'pending',
                'discovered_from_url' => $group->catalogTitle?->source_url ?? $url,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $page = SourcePage::query()->where('url_hash', $urlHash)->firstOrFail();
            $inserted = SeasonvarImportPreparedPage::query()->insertOrIgnore([
                'seasonvar_import_run_id' => $group->seasonvar_import_run_id,
                'seasonvar_import_title_group_id' => $group->id,
                'source_page_id' => $page->id,
                'status' => SeasonvarPreparedPageStatus::Queued->value,
                'warnings' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted !== 1) {
                return false;
            }

            SeasonvarImportTitleGroup::query()->whereKey($group->id)->increment('expected_pages');
            SeasonvarImportRun::query()->whereKey($group->seasonvar_import_run_id)->increment('selected');
            $prepared = SeasonvarImportPreparedPage::query()
                ->where('seasonvar_import_title_group_id', $group->id)
                ->where('source_page_id', $page->id)
                ->firstOrFail();
            $this->dispatchPreparedPage($prepared, $group->queue_name);

            return true;
        });
    }

    private function dispatchPreparedPage(SeasonvarImportPreparedPage $prepared, string $queue): void
    {
        PrepareSeasonvarImportTitlePage::dispatch($prepared->id)
            ->onConnection((string) config('seasonvar.queue.connection', 'redis'))
            ->onQueue($queue)
            ->afterCommit();
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function normalizedUrls(array $urls): array
    {
        return collect($urls)
            ->map(fn (mixed $url): ?string => is_string($url) ? $this->normalizedSerialUrl($url) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizedSerialUrl(string $url): ?string
    {
        try {
            $normalized = $this->seasonvarUrl->normalize($url, $this->seasonvarUrl->baseUrl());
        } catch (Throwable) {
            return null;
        }

        return $this->seasonvarUrl->isAllowed($normalized)
            && $this->seasonvarUrl->pageType($normalized) === SeasonvarPageType::Serial
                ? $normalized
                : null;
    }

    private function finalizerDelaySeconds(): int
    {
        return max(1, (int) config(
            'seasonvar.title_refresh.finalizer_delay_seconds',
            config('seasonvar.queue.finalizer_delay_seconds', 60),
        ));
    }
}
