<?php

declare(strict_types=1);

namespace App\Enums;

enum MediaFileSizeMetadataWriteStatus: string
{
    case Changed = 'changed';
    case Unchanged = 'unchanged';
    case SourceChanged = 'source-changed';
}
