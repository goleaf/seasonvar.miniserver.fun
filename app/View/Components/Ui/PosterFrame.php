<?php

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class PosterFrame extends Component
{
    public string $fit;

    public string $loading;

    public function __construct(
        public ?string $src = null,
        public string $alt = '',
        public string $emptyLabel = 'Нет постера',
        string $loading = 'lazy',
        string $fit = 'cover',
        public bool $overscan = true,
    ) {
        $this->fit = in_array($fit, ['cover', 'contain'], true) ? $fit : 'cover';
        $this->loading = in_array($loading, ['lazy', 'eager'], true) ? $loading : 'lazy';
    }

    public function hasImage(): bool
    {
        return is_string($this->src) && trim($this->src) !== '';
    }

    public function frameClasses(): string
    {
        return 'relative isolate overflow-hidden'.($this->hasImage() && $this->fit === 'cover' ? '' : ' bg-slate-100');
    }

    public function imageClasses(): string
    {
        return collect([
            'absolute inset-0 h-full w-full',
            $this->fit === 'cover' && $this->overscan ? 'scale-[1.02]' : null,
            $this->fit === 'contain' ? 'object-contain' : 'object-cover',
            'object-center',
        ])->filter()->implode(' ');
    }

    public function render(): View
    {
        return view('components.ui.poster-frame');
    }
}
