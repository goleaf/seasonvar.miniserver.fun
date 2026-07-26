<?php

namespace App\Console\Commands;

use App\Enums\CatalogQualityRunStatus;
use App\Models\CatalogQualityRun;
use App\Models\CatalogTitle;
use App\Services\Catalog\Quality\CatalogTitleQualityRecalculator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Throwable;

#[Signature('catalog:quality-refresh
            {--limit= : Максимальное количество карточек в одном запуске}')]
#[Description('Пересчитать ограниченную очередь качества карточек каталога')]
class RefreshCatalogQuality extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CatalogTitleQualityRecalculator $recalculator): int
    {
        if (
            ! Schema::hasTable('catalog_title_quality_snapshots')
            || ! Schema::hasTable('catalog_title_quality_issues')
        ) {
            $this->components->error('Схема центра качества ещё не установлена.');

            return self::FAILURE;
        }

        $limit = $this->resolveLimit();

        if ($limit === null) {
            return self::INVALID;
        }

        $version = (int) config('catalog-quality.scoring_version', 1);
        $staleBefore = CarbonImmutable::now()->subHours(
            max(1, (int) config('catalog-quality.snapshot_stale_after_hours', 24)),
        );
        $ids = CatalogTitle::query()
            ->leftJoin(
                'catalog_title_quality_snapshots as quality',
                'quality.catalog_title_id',
                '=',
                'catalog_titles.id',
            )
            ->where(function (Builder $query) use ($version, $staleBefore): void {
                $query
                    ->whereNull('quality.catalog_title_id')
                    ->orWhere('quality.needs_refresh', true)
                    ->orWhere('quality.scoring_version', '<', $version)
                    ->orWhere('quality.evaluated_at', '<=', $staleBefore);
            })
            ->orderByRaw(
                <<<'SQL'
                    CASE
                        WHEN quality.catalog_title_id IS NULL THEN 0
                        WHEN quality.needs_refresh = 1 THEN 1
                        WHEN quality.scoring_version < ? THEN 2
                        ELSE 3
                    END
                    SQL,
                [$version],
            )
            ->orderBy('catalog_titles.id')
            ->limit($limit)
            ->pluck('catalog_titles.id');

        $run = $this->startRun($limit, $version);

        if ($ids->isEmpty()) {
            $this->completeRun($run, 0);
            $this->components->info('Очередь качества уже актуальна.');

            return self::SUCCESS;
        }

        try {
            $processed = $recalculator->recalculate($ids, $run);
            $this->completeRun($run, $processed);
            $this->components->info("Пересчитано карточек: {$processed}.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($run instanceof CatalogQualityRun) {
                $run->forceFill([
                    'status' => CatalogQualityRunStatus::Failed->value,
                    'completed_at' => now(),
                    'failure_code' => 'quality_refresh_failed',
                ])->save();
            }

            throw $exception;
        }
    }

    private function startRun(int $limit, int $version): ?CatalogQualityRun
    {
        if (! Schema::hasTable('catalog_quality_runs')
            || ! Schema::hasColumn('catalog_title_quality_snapshots', 'catalog_quality_run_id')
            || ! Schema::hasColumn('catalog_title_quality_issues', 'catalog_quality_run_id')) {
            return null;
        }

        return CatalogQualityRun::query()->create([
            'status' => CatalogQualityRunStatus::Running->value,
            'trigger' => 'command',
            'scoring_version' => $version,
            'requested_limit' => $limit,
            'started_at' => now(),
        ]);
    }

    private function completeRun(?CatalogQualityRun $run, int $processed): void
    {
        if (! $run instanceof CatalogQualityRun) {
            return;
        }

        $run->forceFill([
            'status' => CatalogQualityRunStatus::Succeeded->value,
            'processed_count' => $processed,
            'issue_count' => $run->issues()->count(),
            'completed_at' => now(),
            'failure_code' => null,
        ])->save();
    }

    private function resolveLimit(): ?int
    {
        $option = $this->option('limit');
        $default = max(1, (int) config('catalog-quality.scheduled_batch_size', 250));
        $maximum = max($default, (int) config('catalog-quality.command_max_batch_size', 1_000));

        if ($option === null || $option === '') {
            return min($default, $maximum);
        }

        if (preg_match('/^\d+$/', $option) !== 1) {
            $this->components->error('Параметр --limit должен быть целым числом.');

            return null;
        }

        $limit = (int) $option;

        if ($limit < 1 || $limit > $maximum) {
            $this->components->error("Параметр --limit должен быть от 1 до {$maximum}.");

            return null;
        }

        return $limit;
    }
}
