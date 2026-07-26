<?php

declare(strict_types=1);

namespace App\Enums;

enum PlaybackPreferenceMode: string
{
    case Automatic = 'automatic';
    case Dubbed = 'dubbed';
    case OriginalSubtitles = 'original_subtitles';
}
