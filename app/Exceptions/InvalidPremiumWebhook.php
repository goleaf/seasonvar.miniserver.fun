<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class InvalidPremiumWebhook extends RuntimeException implements ShouldntReport {}
