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
        'list' => 'grid grid-cols-[4rem_minmax(0,1fr)] gap-3 p-3 hover:bg-emerald-50/60 sm:grid-cols-[5rem_minmax(0,1fr)] sm:gap-4 sm:p-4 md:grid-cols-[6rem_minmax(0,1fr)]',
        'compact' => 'grid grid-cols-[3.5rem_minmax(0,1fr)] gap-3 p-3 hover:bg-emerald-50/60 sm:grid-cols-[4rem_minmax(0,1fr)]',
        'recommendation' => 'grid grid-cols-[4rem_minmax(0,1fr)] gap-3 p-3 hover:bg-emerald-50/60 sm:grid-cols-[5rem_minmax(0,1fr)] sm:gap-4 sm:p-4 md:grid-cols-[6rem_minmax(0,1fr)]',
        'stats' => 'grid grid-cols-[5.5rem_minmax(0,1fr)] shadow-panel sm:flex sm:h-full sm:flex-col motion-safe:hover:-translate-y-0.5 motion-safe:hover:shadow-panel-hover',
    ];

    /**
     * @var array<string, string>
     */
    private const MEDIA_CLASSES = [
        'list' => 'relative aspect-[2/3] w-16 self-start overflow-hidden rounded-control sm:w-20 md:w-24',
        'compact' => 'relative aspect-[2/3] w-14 self-start overflow-hidden rounded-control sm:w-16',
        'recommendation' => 'relative aspect-[2/3] w-16 self-start overflow-hidden rounded-control sm:w-20 md:w-24',
        'stats' => 'relative min-h-[8.25rem] w-full sm:aspect-[2/3] sm:min-h-0',
    ];

    /**
     * @var array<string, string>
     */
    private const BODY_CLASSES = [
        'list' => 'min-w-0',
        'compact' => 'min-w-0',
        'recommendation' => 'min-w-0',
        'stats' => 'flex min-w-0 flex-1 flex-col p-3 sm:p-4',
    ];

    public string $layout;

    public function __construct(
        public ?string $src = null,
        public string $alt = '',
        public string $emptyLabel = 'Нет постера',
        public string $loading = 'lazy',
        string $layout = 'list',
    ) {
        $this->layout = array_key_exists($layout, self::ROOT_CLASSES) ? $layout : 'list';
    }

    public function rootClasses(): string
    {
        if ($this->layout !== 'stats') {
            return implode(' ', [
                'group relative min-w-0 transition',
                self::ROOT_CLASSES[$this->layout],
            ]);
        }

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
