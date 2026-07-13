<?php

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class PosterCard extends Component
{
    /**
     * @var array<string, string>
     */
    private const ROOT_CLASSES = [
        'grid' => 'grid grid-cols-[5.5rem_minmax(0,1fr)] shadow-panel sm:flex sm:h-full sm:flex-col motion-safe:hover:-translate-y-0.5 motion-safe:hover:shadow-panel-hover',
        'horizontal' => 'grid grid-cols-[5rem_minmax(0,1fr)] hover:bg-emerald-50',
        'compact' => 'grid grid-cols-[4rem_minmax(0,1fr)] hover:bg-emerald-50',
    ];

    /**
     * @var array<string, string>
     */
    private const MEDIA_CLASSES = [
        'grid' => 'relative min-h-[8.25rem] w-full sm:aspect-[2/3] sm:min-h-0',
        'horizontal' => 'relative min-h-28 w-full',
        'compact' => 'relative min-h-24 w-full',
    ];

    /**
     * @var array<string, string>
     */
    private const BODY_CLASSES = [
        'grid' => 'flex min-w-0 flex-1 flex-col p-3 sm:p-4',
        'horizontal' => 'min-w-0 p-3 sm:p-4',
        'compact' => 'min-w-0 p-3',
    ];

    public string $layout;

    public function __construct(
        public ?string $src = null,
        public string $alt = '',
        public string $emptyLabel = 'Нет постера',
        public string $loading = 'lazy',
        string $layout = 'grid',
    ) {
        $this->layout = array_key_exists($layout, self::ROOT_CLASSES) ? $layout : 'grid';
    }

    public function rootClasses(): string
    {
        return implode(' ', [
            'group relative min-w-0 overflow-hidden rounded-panel border border-slate-200 bg-white transition',
            self::ROOT_CLASSES[$this->layout],
        ]);
    }

    public function mediaClasses(): string
    {
        return self::MEDIA_CLASSES[$this->layout];
    }

    public function bodyClasses(): string
    {
        return self::BODY_CLASSES[$this->layout];
    }

    public function render(): View
    {
        return view('components.ui.poster-card');
    }
}
