@props([
    'filterView',
    'mobile' => false,
])

<nav
    data-catalog-alphabet-groups
    @if (! $mobile) data-catalog-desktop-alphabet @endif
    aria-label="{{ $mobile ? 'Мобильный алфавитный переход по названиям' : 'Алфавитный переход по названиям' }}"
    {{ $attributes }}
>
    <div class="flex flex-wrap items-center gap-1.5">
        <span class="mr-1 text-xs font-bold uppercase tracking-wide text-slate-600">{{ __('catalog.catalog.alphabet.label') }}:</span>
        <a
            data-catalog-alphabet-option
            data-alphabet-letter=""
            href="{{ route('titles.index', $filterView->alphabetQuery('')) }}"
            rel="nofollow"
            wire:click.prevent="setLetter('')"
            @if ($filterView->isActiveLetter('')) aria-current="true" @endif
        >{{ __('catalog.catalog.alphabet.all') }}</a>
    </div>

    @foreach (['symbols', 'cyrillic', 'latin'] as $group)
        @if ($filterView->alphabetGroups[$group] !== [])
            <div data-catalog-alphabet-group="{{ $group }}" class="mt-2 grid gap-2 sm:grid-cols-[6.5rem_minmax(0,1fr)] sm:items-start">
                <span class="py-3 text-xs font-bold text-slate-500">{{ __("catalog.catalog.alphabet.{$group}") }}</span>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($filterView->alphabetGroups[$group] as $letter)
                        <a
                            data-catalog-alphabet-option
                            data-alphabet-letter="{{ $letter }}"
                            href="{{ route('titles.index', $filterView->alphabetQuery($letter)) }}"
                            rel="nofollow"
                            wire:click.prevent="setLetter(@js($letter))"
                            @if ($filterView->isActiveLetter($letter)) aria-current="true" @endif
                        >{{ $letter }}</a>
                    @endforeach
                </div>
            </div>
        @endif
    @endforeach
</nav>
