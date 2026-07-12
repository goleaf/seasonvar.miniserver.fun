<?php

namespace App\Enums;

enum ContentAudience: string
{
    case Public = 'public';
    case Authenticated = 'authenticated';
}
