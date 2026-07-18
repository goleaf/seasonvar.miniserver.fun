@props([
    'value',
    'max' => 100,
    'label',
    'valueText' => null,
    'tone' => 'emerald',
    'size' => 'md',
])

<progress
    {{ $attributes->class(['ui-progress', 'ui-progress--'.$tone, 'ui-progress--'.$size]) }}
    value="{{ $value }}"
    max="{{ $max }}"
    aria-label="{{ $label }}"
    @if ($valueText !== null) aria-valuetext="{{ $valueText }}" @endif
></progress>
