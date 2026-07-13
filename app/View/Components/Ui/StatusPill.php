<?php

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class StatusPill extends Component
{
    /**
     * @var array<string, string>
     */
    private const VARIANT_CLASSES = [
        'success' => 'bg-emerald-50 text-emerald-700',
        'warning' => 'bg-amber-50 text-amber-700',
        'neutral' => 'bg-slate-50 text-slate-600',
        'muted' => 'bg-slate-50 text-slate-500',
    ];

    /**
     * @var array<string, string>
     */
    private const SIZE_CLASSES = [
        'xs' => 'px-2 py-0.5 text-[11px]',
        'sm' => 'px-2 py-1 text-xs',
        'md' => 'px-3 py-1 text-xs',
        'tile' => 'justify-center px-2 py-2 text-xs',
    ];

    /**
     * @var array<string, string>
     */
    private const SHAPE_CLASSES = [
        'pill' => 'rounded-full',
        'lg' => 'rounded-control',
    ];

    public function __construct(
        public ?string $icon = null,
        public string $variant = 'neutral',
        public string $size = 'sm',
        public string $shape = 'pill',
    ) {}

    public function classes(): string
    {
        return implode(' ', [
            'inline-flex max-w-full items-center gap-1 font-bold',
            self::VARIANT_CLASSES[$this->variant] ?? self::VARIANT_CLASSES['neutral'],
            self::SIZE_CLASSES[$this->size] ?? self::SIZE_CLASSES['sm'],
            self::SHAPE_CLASSES[$this->shape] ?? self::SHAPE_CLASSES['pill'],
        ]);
    }

    public function render(): View
    {
        return view('components.ui.status-pill');
    }
}
