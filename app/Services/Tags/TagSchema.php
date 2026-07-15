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
            return $this->available = Schema::hasTable('tag_translations');
        } catch (Throwable) {
            return $this->available = false;
        }
    }
}
