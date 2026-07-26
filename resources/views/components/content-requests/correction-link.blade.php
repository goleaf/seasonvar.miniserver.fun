@props([
    'url',
    'field',
    'label' => null,
])

<a
    href="{{ $url }}"
    data-correction-field="{{ $field }}"
    {{ $attributes->class([
        'inline-flex min-h-11 shrink-0 items-center gap-2 rounded-control border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-900 transition hover:border-amber-300 hover:bg-amber-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-700',
    ]) }}
>
    <x-ui.icon name="fa-solid fa-pen-to-square" />
    <span>{{ $label ?? __('requests.actions.correct_data') }}</span>
</a>
