<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use Illuminate\Support\Facades\Schema;

final class UserProfileSchema
{
    private ?bool $available = null;

    public function available(): bool
    {
        return $this->available ??= Schema::hasTable('user_profiles')
            && Schema::hasTable('user_profile_username_histories')
            && Schema::hasTable('user_profile_reports');
    }
}
