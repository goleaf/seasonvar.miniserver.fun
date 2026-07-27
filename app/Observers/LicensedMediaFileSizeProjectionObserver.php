<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\LicensedMedia;
use App\Services\Media\LicensedMediaFileSizeScheduleProjection;

final readonly class LicensedMediaFileSizeProjectionObserver
{
    public function __construct(
        private LicensedMediaFileSizeScheduleProjection $projection,
    ) {}

    public function saving(LicensedMedia $media): void
    {
        $this->projection->applyTo($media);
    }
}
