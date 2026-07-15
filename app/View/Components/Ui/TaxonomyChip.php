<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\Component;
use Illuminate\View\ComponentSlot;

final class TaxonomyChip extends Component
{
    private const ICONS = [
        'genre' => 'fa-solid fa-masks-theater',
        'country' => 'fa-solid fa-earth-europe',
        'actor' => 'fa-solid fa-user-group',
        'director' => 'fa-solid fa-video',
        'age_rating' => 'fa-solid fa-shield-halved',
        'translation' => 'fa-solid fa-language',
        'status' => 'fa-solid fa-signal',
        'network' => 'fa-solid fa-tower-broadcast',
        'studio' => 'fa-solid fa-building',
        'tag' => 'fa-solid fa-tag',
    ];

    public function __construct(
        public ?Model $taxonomy = null,
        public ?string $href = null,
        public bool $active = false,
        public int|string|null $count = null,
        public bool $muted = false,
        public ?string $icon = null,
    ) {}

    public function label(ComponentSlot $slot): string
    {
        $label = $this->taxonomy?->getAttribute('name');

        return is_string($label) && $label !== '' ? $label : trim((string) $slot);
    }

    public function url(): ?string
    {
        if ($this->href !== null) {
            return $this->href;
        }

        $type = $this->filterType();

        $slug = $this->taxonomy?->getAttribute('slug');

        if ($type === null || ! is_string($slug) || $slug === '') {
            return null;
        }

        return route('titles.taxonomy', ['type' => $type, 'taxonomy' => $slug]);
    }

    public function iconClass(): ?string
    {
        $type = $this->filterType();

        return $this->icon ?? ($type === null ? null : (self::ICONS[$type] ?? null));
    }

    public function classes(): string
    {
        $classes = 'inline-flex max-w-full items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold transition';
        $stateClasses = match (true) {
            $this->active => 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100',
            $this->muted => 'bg-slate-50 text-slate-500',
            default => 'bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700',
        };

        return $classes.' '.$stateClasses;
    }

    public function ariaLabel(ComponentSlot $slot): ?string
    {
        if ($this->filterType() !== 'tag') {
            return null;
        }

        $label = __('tags.accessibility.system_badge', ['tag' => $this->label($slot)]);

        if (is_numeric($this->count)) {
            $count = (int) $this->count;
            $label .= ' · '.trans_choice('tags.page.count', $count, ['count' => $count]);
        }

        return $label;
    }

    public function render(): View
    {
        return view('components.ui.taxonomy-chip');
    }

    private function filterType(): ?string
    {
        if ($this->taxonomy === null) {
            return null;
        }

        if (method_exists($this->taxonomy, 'filterType')) {
            $filterType = $this->taxonomy->filterType();

            return is_string($filterType) ? $filterType : null;
        }

        $filterType = $this->taxonomy->getAttribute('type');

        return is_string($filterType) ? $filterType : null;
    }
}
