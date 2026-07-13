<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Enums\SeasonvarSourceAvailability;
use Illuminate\Support\Str;

final class SeasonvarSourceAvailabilityDetector
{
    private const RIGHTS_HOLDER_REGION_BLOCK_MESSAGE = 'по просьбе правообладателя, сезон заблокирован для вашей страны';

    public function detect(string $html): ?SeasonvarSourceAvailability
    {
        $text = Str::of(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            ->replace("\xc2\xa0", ' ')
            ->squish()
            ->lower();

        return $text->contains(self::RIGHTS_HOLDER_REGION_BLOCK_MESSAGE)
            ? SeasonvarSourceAvailability::RegionBlocked
            : null;
    }
}
