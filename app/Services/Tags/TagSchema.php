<?php

declare(strict_types=1);

namespace App\Services\Tags;

use Illuminate\Support\Facades\Schema;
use Throwable;

final class TagSchema
{
    private ?bool $available = null;

    public function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            return $this->available = Schema::hasColumns('tags', [
                'public_id',
                'type',
                'visibility',
                'moderation_status',
                'normalized_name_hash',
            ])
                && Schema::hasTable('tag_translations')
                && Schema::hasTable('tag_aliases')
                && Schema::hasTable('user_tags')
                && Schema::hasTable('catalog_title_user_tag');
        } catch (Throwable) {
            return $this->available = false;
        }
    }
}
