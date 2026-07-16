<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;
use Illuminate\View\Component;

final class Stat extends Component
{
    public string $formattedValue;

    public function __construct(
        public string $label,
        int|float|string $value,
        public ?string $icon = null,
    ) {
        $this->formattedValue = Number::format((float) $value, locale: app()->currentLocale());
    }

    public function render(): View
    {
        return view('components.stat');
    }
}
