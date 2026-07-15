<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Sync;

use Illuminate\Support\Facades\Schema;

final class ApiSyncReadiness
{
    public function available(): bool
    {
        return Schema::hasTable('api_sync_changes')
            && Schema::hasTable('api_sync_mutations')
            && $this->stateVersionsAvailable();
    }

    public function stateVersionsAvailable(): bool
    {
        return Schema::hasTable('catalog_title_user_states')
            && Schema::hasColumns('catalog_title_user_states', [
                'watchlist_version',
                'rating_version',
            ]);
    }
}
