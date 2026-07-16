<?php

declare(strict_types=1);

namespace App\DTOs\Profiles;

use App\Models\UserProfile;

final readonly class ResolvedUserProfile
{
    public function __construct(
        public UserProfile $profile,
        public bool $fromHistory,
    ) {}
}
