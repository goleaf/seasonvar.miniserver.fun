@props(['name' => null, 'align' => 'center'])

<i
    data-ui-icon="true"
    aria-hidden="true"
    {{ $attributes->class([
        'ui-icon',
        'ui-icon--start' => $align === 'start',
        $name,
    ]) }}
></i>
