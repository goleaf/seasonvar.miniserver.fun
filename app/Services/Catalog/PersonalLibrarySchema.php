<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Schema;

final class PersonalLibrarySchema
{
    private ?bool $ready = null;

    public function ready(): bool
    {
        return $this->ready ??= Schema::hasColumn('episode_view_progress', 'completion_source')
            && Schema::hasTable('episode_playback_markers')
            && Schema::hasTable('catalog_title_update_states');
    }
}
