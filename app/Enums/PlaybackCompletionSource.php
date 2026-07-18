<?php

declare(strict_types=1);

namespace App\Enums;

enum PlaybackCompletionSource: string
{
    case Playback = 'playback';
    case Manual = 'manual';
}
