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
        'grid' => 'flex h-full flex-col rounded-panel border border-slate-200 bg-white p-3 hover:border-emerald-300 focus-within:border-emerald-400',
        'list' => 'grid grid-cols-[5.5rem_minmax(0,1fr)] gap-3 p-3 hover:bg-emerald-50/60 focus-within:bg-emerald-50/60 sm:grid-cols-[7.5rem_minmax(0,1fr)] sm:gap-4 sm:p-4 lg:grid-cols-[9rem_minmax(0,1fr)]',
        'compact' => 'grid grid-cols-[3.5rem_minmax(0,1fr)] gap-3 p-3 hover:bg-emerald-50/60 sm:grid-cols-[4rem_minmax(0,1fr)]',
        'recommendation' => 'grid grid-cols-[4rem_minmax(0,1fr)] gap-3 p-3 hover:bg-emerald-50/60 sm:grid-cols-[5rem_minmax(0,1fr)] sm:gap-4 sm:p-4 md:grid-cols-[6rem_minmax(0,1fr)]',
        'stats' => 'grid grid-cols-[5.5rem_minmax(0,1fr)] sm:flex sm:h-full sm:flex-col',
        'home' => 'flex h-full flex-col',
        'spotlight' => 'flex flex-col lg:grid lg:grid-cols-[minmax(11rem,0.85fr)_minmax(0,1fr)] lg:gap-5 lg:rounded-panel lg:border lg:border-slate-200 lg:bg-white lg:p-5',
        'trend' => 'flex flex-col lg:grid lg:grid-cols-[4rem_minmax(0,1fr)] lg:gap-3 lg:p-3',
    ];

    /**
     * @var array<string, string>
     */
    private const MEDIA_CLASSES = [
        'grid' => 'relative aspect-[2/3] w-full overflow-hidden rounded-lg bg-slate-100',
        'list' => 'relative aspect-[2/3] w-[5.5rem] self-start overflow-hidden rounded-control sm:w-[7.5rem] lg:w-36',
        'compact' => 'relative aspect-[2/3] w-14 self-start overflow-hidden rounded-control sm:w-16',
        'recommendation' => 'relative aspect-[2/3] w-16 self-start overflow-hidden rounded-control sm:w-20 md:w-24',
        'stats' => 'relative min-h-[8.25rem] w-full sm:aspect-[2/3] sm:min-h-0',
        'home' => 'relative aspect-[2/3] w-full overflow-hidden rounded-panel bg-slate-100',
        'spotlight' => 'relative aspect-[2/3] w-full overflow-hidden rounded-panel bg-slate-100',
        'trend' => 'relative aspect-[2/3] w-full overflow-hidden rounded-panel bg-slate-100 lg:w-16',
    ];

    /**
     * @var array<string, string>
     */
    private const BODY_CLASSES = [
        'grid' => 'flex min-w-0 flex-1 flex-col pt-3',
        'list' => 'min-w-0',
        'compact' => 'min-w-0',
        'recommendation' => 'min-w-0',
        'stats' => 'flex min-w-0 flex-1 flex-col p-3 sm:p-4',
        'home' => 'flex min-w-0 flex-1 flex-col pt-3',
        'spotlight' => 'flex min-w-0 flex-1 flex-col pt-3 lg:pt-0',
        'trend' => 'flex min-w-0 flex-1 flex-col pt-3 lg:pt-0',
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
