@props([
    'caption',
    'columns' => [],
    'empty' => false,
])

<div class="overflow-hidden rounded-panel border border-slate-200 bg-white shadow-panel">
    <div data-horizontal-scroll-contract="wide-administration-table" class="overflow-x-auto" role="region" aria-label="{{ $caption }}" tabindex="0">
        <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
            <caption class="sr-only">{{ $caption }}</caption>
            <thead class="bg-slate-50">
                <tr>
                    @foreach ($columns as $column)
                        <th scope="col" class="whitespace-nowrap px-4 py-3 text-xs font-black uppercase tracking-[0.08em] text-slate-600">
                            {{ $column->label }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                {{ $slot }}
            </tbody>
        </table>
    </div>

    @if ($empty)
        <div class="border-t border-slate-100 p-4">
            {{ $emptyState ?? '' }}
        </div>
    @endif

    @isset($pagination)
        <div class="border-t border-slate-100 px-4 py-3">
            {{ $pagination }}
        </div>
    @endisset
</div>
