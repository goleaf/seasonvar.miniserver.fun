<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\User;
use App\Services\Media\LicensedMediaDownloadEligibility;

final class LicensedMediaPolicy
{
    public function __construct(
        private readonly LicensedMediaDownloadEligibility $downloads,
    ) {}

    public function download(User $user, LicensedMedia $media): bool
    {
        $media->loadMissing('catalogTitle');
        $title = $media->catalogTitle;

        return $title instanceof CatalogTitle
            && $this->downloads->resolve($user, $title, $media)->eligible;
    }
}
