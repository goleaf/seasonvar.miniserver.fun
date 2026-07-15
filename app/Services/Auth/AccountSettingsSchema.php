<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\Schema;

final class AccountSettingsSchema
{
    private ?bool $available = null;

    public function available(): bool
    {
        return $this->available ??= Schema::hasTable('user_account_settings');
    }
}
