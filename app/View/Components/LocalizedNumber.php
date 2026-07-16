<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;
use Illuminate\View\Component;

final class LocalizedNumber extends Component
{
    public string $formattedValue;

    public function __construct(int|float|string $value)
    {
        $this->formattedValue = Number::format((float) $value, locale: app()->currentLocale());
    }

    public function render(): View
    {
        return view('components.localized-number');
    }
}
