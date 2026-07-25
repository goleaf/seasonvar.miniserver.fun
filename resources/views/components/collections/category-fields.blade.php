@props([
    'rootOptions',
    'childOptions',
    'assignmentArchived' => false,
    'rootModel' => 'categoryRootPublicId',
    'categoryModel' => 'categoryPublicId',
    'idPrefix' => 'collection-category',
])

<fieldset class="grid gap-4 rounded-control border border-slate-200 bg-slate-50 p-4 sm:grid-cols-2">
    <legend class="px-1 text-sm font-black text-slate-800">{{ __('collections.categories.title') }}</legend>

    <div>
        <label for="{{ $idPrefix }}-root" class="block text-sm font-bold text-slate-700">{{ __('collections.categories.root_label') }}</label>
        <select
            id="{{ $idPrefix }}-root"
            wire:model.live="{{ $rootModel }}"
            class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100"
        >
            <option value="">{{ __('collections.categories.select_root') }}</option>
            @foreach ($rootOptions as $option)
                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="{{ $idPrefix }}-child" class="block text-sm font-bold text-slate-700">{{ __('collections.categories.subcategory_label') }}</label>
        <select
            id="{{ $idPrefix }}-child"
            wire:model.live="{{ $categoryModel }}"
            @disabled($rootOptions === [] || $childOptions === [])
            class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100"
        >
            <option value="">{{ __('collections.categories.no_subcategory') }}</option>
            @foreach ($childOptions as $option)
                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
            @endforeach
        </select>
    </div>

    <div class="sm:col-span-2">
        <p class="text-xs leading-5 text-slate-500">{{ __('collections.categories.assignment_hint') }}</p>
        @if ($assignmentArchived)
            <p class="mt-2 rounded-control bg-amber-50 px-3 py-2 text-sm font-bold text-amber-800">
                {{ __('collections.categories.archived_assignment') }}
            </p>
        @endif
        <x-form.input-error for="categoryPublicId" />
        <x-form.input-error for="categoryRootPublicId" />
    </div>
</fieldset>
