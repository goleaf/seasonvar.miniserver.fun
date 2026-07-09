<?php

namespace App\Livewire;

use App\Services\Catalog\CatalogStatsSnapshotCache;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class StatsDashboard extends Component
{
    protected CatalogStatsSnapshotCache $snapshots;

    public function boot(CatalogStatsSnapshotCache $snapshots): void
    {
        $this->snapshots = $snapshots;
    }

    public function refreshStats(): void {}

    public function render(): View
    {
        $snapshot = $this->snapshots->snapshot();

        return view('livewire.stats-dashboard', [
            'stats' => $snapshot['data'],
            'snapshotMeta' => $snapshot['meta'],
        ]);
    }
}
