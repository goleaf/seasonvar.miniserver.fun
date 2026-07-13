<?php

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class PosterFrame extends Component
{
    public string $loading;

    public function __construct(
        public ?string $src = null,
        public string $alt = '',
        public string $emptyLabel = 'Нет постера',
        string $loading = 'lazy',
        public bool $overscan = true,
    ) {
        $this->loading = in_array($loading, ['lazy', 'eager'], true) ? $loading : 'lazy';
    }

    public function hasImage(): bool
    {
        return is_string($this->src) && trim($this->src) !== '';
    }

    public function frameClasses(): string
    {
        return 'relative isolate overflow-hidden'.($this->hasImage() ? '' : ' bg-slate-100');
    }

    public function render(): View
    {
        return view('components.ui.poster-frame');
    }
}
