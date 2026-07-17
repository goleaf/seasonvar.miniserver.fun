<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\Catalog\CatalogStatsPageBuilder;
use App\Services\Catalog\CatalogStatsSnapshotCache;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class StatsDashboard extends Component
{
    protected CatalogStatsSnapshotCache $snapshots;

    public function boot(CatalogStatsSnapshotCache $snapshots): void
    {
        $this->snapshots = $snapshots;
    }

    public function render(CatalogStatsPageBuilder $page): View
    {
        $snapshot = $this->snapshots->snapshot();
        $seo = $page->seo();

        return view('livewire.stats-dashboard', [
            'stats' => $snapshot['data'],
            'snapshotMeta' => $snapshot['meta'],
        ])->extends('layouts.app', [
            'title' => $seo['title'] ?? 'Сводка каталога',
            'seo' => $seo,
        ])->section('content');
    }
}
