<?php

declare(strict_types=1);

namespace App\Enums;

enum ContentRequestDuplicateConfidence: string
{
    case Exact = 'exact';
    case Probable = 'probable';
    case Related = 'related';
    case None = 'none';
}
