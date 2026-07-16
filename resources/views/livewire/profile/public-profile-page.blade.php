<div class="mx-auto max-w-6xl space-y-5">
    <article class="overflow-hidden rounded-panel border border-slate-200 bg-white shadow-panel">
        <div class="relative min-h-28 bg-slate-100 sm:min-h-40">
            @if ($profileData->coverUrl)
                <img src="{{ $profileData->coverUrl }}" alt="" class="absolute inset-0 h-full w-full object-cover" loading="eager">
            @endif
        </div>

        <div class="relative px-4 pb-5 sm:px-6 sm:pb-6">
            <div class="-mt-10 flex flex-col gap-4 sm:-mt-12 sm:flex-row sm:items-end sm:justify-between">
                <div class="flex min-w-0 items-end gap-4">
                    @if ($profileData->avatarUrl)
                        <img src="{{ $profileData->avatarUrl }}" alt="{{ __('profiles.accessibility.avatar', ['name' => $profileData->displayName]) }}" class="h-20 w-20 shrink-0 rounded-full border-4 border-white bg-white object-cover shadow-panel sm:h-24 sm:w-24">
                    @else
                        <span aria-hidden="true" class="grid h-20 w-20 shrink-0 place-items-center rounded-full border-4 border-white bg-emerald-50 text-2xl font-black text-emerald-700 shadow-panel sm:h-24 sm:w-24">{{ $profileData->initial }}</span>
                    @endif
                    <div class="min-w-0 pb-1">
                        <h1 class="break-words text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">{{ $profileData->displayName }}</h1>
                        <p class="mt-1 break-all text-sm font-bold text-slate-500">{{ '@'.$profileData->username }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    @if ($profileData->isOwner)
                        <a href="{{ route('profile.show') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800"><x-ui.icon name="fa-solid fa-pen" />{{ __('profiles.actions.edit') }}</a>
                    @else
                        @if ($profileData->canMute)
                            <button type="button" wire:click="toggleMute" wire:loading.attr="disabled" wire:target="toggleMute" aria-pressed="{{ $profileData->isMuted ? 'true' : 'false' }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200 disabled:opacity-60"><x-ui.icon name="fa-solid fa-volume-xmark" />{{ $profileData->isMuted ? __('profiles.actions.unmute') : __('profiles.actions.mute') }}</button>
                        @endif
                        @if ($profileData->canReport)
                            <button type="button" wire:click="openReport" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-amber-50 px-4 py-2.5 text-sm font-bold text-amber-800 hover:bg-amber-100"><x-ui.icon name="fa-solid fa-flag" />{{ __('profiles.actions.report') }}</button>
                        @endif
                        @if ($profileData->canBlock)
                            <button type="button" wire:click="block" wire:confirm="{{ __('profiles.actions.block_confirm') }}" wire:loading.attr="disabled" wire:target="block" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-rose-50 px-4 py-2.5 text-sm font-bold text-rose-700 hover:bg-rose-100 disabled:opacity-60"><x-ui.icon name="fa-solid fa-ban" />{{ __('profiles.actions.block') }}</button>
                        @endif
                    @endif
                </div>
            </div>

            @if ($profileData->memberSince)
                <p class="mt-4 text-xs font-bold text-slate-500">{{ __('profiles.member_since', ['date' => $profileData->memberSince]) }}</p>
            @endif

            @if ($profileData->biography)
                <p class="mt-4 max-w-3xl whitespace-pre-line break-words text-sm leading-7 text-slate-700">{{ $profileData->biography }}</p>
            @endif
        </div>
    </article>

    <div aria-live="polite" class="space-y-2">
        @if ($notice)<x-form.status-message :message="$notice" />@endif
        @if ($actionError)<div role="alert" class="rounded-control border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">{{ $actionError }}</div>@endif
    </div>

    <nav aria-label="{{ __('profiles.navigation.label') }}" class="rounded-panel border border-slate-200 bg-white p-2 shadow-panel">
        <div role="tablist" class="flex flex-wrap gap-1">
            @foreach ([
                ['key' => 'overview', 'label' => __('profiles.navigation.overview'), 'visible' => true, 'count' => null],
                ['key' => 'reviews', 'label' => __('profiles.navigation.reviews'), 'visible' => $profileData->sections['reviews'], 'count' => $profileData->counts['reviews']],
                ['key' => 'comments', 'label' => __('profiles.navigation.comments'), 'visible' => $profileData->sections['comments'], 'count' => $profileData->counts['comments']],
                ['key' => 'collections', 'label' => __('profiles.navigation.collections'), 'visible' => $profileData->sections['collections'], 'count' => $profileData->counts['collections']],
                ['key' => 'watching', 'label' => __('profiles.navigation.watching'), 'visible' => $profileData->sections['watching'], 'count' => $profileData->counts['watching']],
                ['key' => 'completed', 'label' => __('profiles.navigation.completed'), 'visible' => $profileData->sections['completed'], 'count' => $profileData->counts['completed']],
            ] as $profileTab)
                @if ($profileTab['visible'])
                    <button id="profile-tab-{{ $profileTab['key'] }}" type="button" role="tab" wire:click="$set('tab', '{{ $profileTab['key'] }}')" aria-selected="{{ $tab === $profileTab['key'] ? 'true' : 'false' }}" aria-controls="profile-tab-panel" @class(['inline-flex min-h-11 items-center gap-2 rounded-control px-4 py-2 text-sm font-bold', 'bg-emerald-700 text-white' => $tab === $profileTab['key'], 'text-slate-600 hover:bg-slate-100' => $tab !== $profileTab['key']])>
                        {{ $profileTab['label'] }}
                        @if ($profileTab['count'] !== null)<span class="tabular-nums">{{ $profileTab['count'] }}</span>@endif
                    </button>
                @endif
            @endforeach
        </div>
    </nav>

    <section id="profile-tab-panel" role="tabpanel" aria-labelledby="profile-tab-{{ $tab }}" aria-live="polite" wire:loading.class="opacity-60" wire:target="tab">
        @if ($tab === 'overview')
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['key' => 'reviews', 'icon' => 'fa-solid fa-star-half-stroke', 'label' => __('profiles.navigation.reviews')],
                    ['key' => 'comments', 'icon' => 'fa-solid fa-comments', 'label' => __('profiles.navigation.comments')],
                    ['key' => 'collections', 'icon' => 'fa-solid fa-layer-group', 'label' => __('profiles.navigation.collections')],
                    ['key' => 'completed', 'icon' => 'fa-solid fa-circle-check', 'label' => __('profiles.navigation.completed')],
                ] as $stat)
                    @if ($profileData->sections[$stat['key']] ?? false)
                        <button type="button" wire:click="$set('tab', '{{ $stat['key'] }}')" class="rounded-panel border border-slate-200 bg-white p-5 text-left shadow-panel hover:border-emerald-200">
                            <x-ui.icon :name="$stat['icon'].' text-emerald-700'" />
                            <p class="mt-3 text-2xl font-black tabular-nums text-slate-900">{{ $profileData->counts[$stat['key']] }}</p>
                            <p class="mt-1 text-sm font-bold text-slate-500">{{ $stat['label'] }}</p>
                        </button>
                    @endif
                @endforeach
            </div>
            @unless ($hasPublicSections)
                <div class="rounded-panel border border-dashed border-slate-300 bg-white p-8 text-center shadow-panel"><p class="text-sm font-semibold text-slate-600">{{ __('profiles.empty.no_public_activity') }}</p></div>
            @endunless
        @elseif ($items !== null && $items->isEmpty())
            <div class="rounded-panel border border-dashed border-slate-300 bg-white p-8 text-center shadow-panel"><p class="text-sm font-semibold text-slate-600">{{ __('profiles.empty.'.$tab) }}</p></div>
        @elseif ($tab === 'collections')
            <div class="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($items as $collection)
                    <x-collections.collection-card wire:key="public-profile-collection-{{ $collection->public_id }}" :collection="$collection" />
                @endforeach
            </div>
            <nav class="mt-5" aria-label="{{ __('profiles.pagination') }}">{{ $items->links() }}</nav>
        @elseif ($tab === 'reviews')
            <div class="space-y-4">
                @foreach ($items as $item)
                    <article class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                @if ($item->title)<h2 class="break-words text-lg font-black text-slate-900">{{ $item->title }}</h2>@endif
                                @if ($item->targetUrl && $item->targetTitle)<a href="{{ $item->targetUrl }}" class="mt-1 inline-flex min-h-9 items-center gap-2 break-words text-sm font-bold text-emerald-700 hover:text-emerald-600"><x-ui.icon name="fa-solid fa-clapperboard" />{{ $item->targetTitle }}</a>@endif
                            </div>
                            <span class="text-xs font-semibold text-slate-500">{{ $item->publishedAt }}</span>
                        </div>
                        @if ($item->isSpoiler)
                            <p class="mt-4 rounded-control bg-amber-50 px-3 py-3 text-sm font-semibold text-amber-900"><x-ui.icon name="fa-solid fa-eye-slash" /> {{ __('profiles.spoiler_hidden') }}</p>
                        @elseif ($item->excerpt)
                            <p class="mt-4 whitespace-pre-line break-words text-sm leading-7 text-slate-700">{{ $item->excerpt }}</p>
                        @endif
                        <a href="{{ $item->directUrl }}" class="mt-4 inline-flex min-h-10 items-center text-sm font-bold text-slate-600 hover:text-emerald-700">{{ __('profiles.actions.open') }}</a>
                    </article>
                @endforeach
            </div>
            <nav class="mt-5" aria-label="{{ __('profiles.pagination') }}">{{ $items->links() }}</nav>
        @elseif ($tab === 'comments')
            <div class="space-y-4">
                @foreach ($items as $item)
                    <article class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                @if ($item->targetUrl && $item->targetTitle)<a href="{{ $item->targetUrl }}" class="mt-1 inline-flex min-h-9 items-center gap-2 break-words text-sm font-bold text-emerald-700 hover:text-emerald-600"><x-ui.icon name="fa-solid fa-clapperboard" />{{ $item->targetTitle }}</a>@endif
                            </div>
                            <span class="text-xs font-semibold text-slate-500">{{ $item->publishedAt }}</span>
                        </div>
                        @if ($item->isSpoiler)
                            <p class="mt-4 rounded-control bg-amber-50 px-3 py-3 text-sm font-semibold text-amber-900"><x-ui.icon name="fa-solid fa-eye-slash" /> {{ __('profiles.spoiler_hidden') }}</p>
                        @elseif ($item->excerpt)
                            <p class="mt-4 whitespace-pre-line break-words text-sm leading-7 text-slate-700">{{ $item->excerpt }}</p>
                        @endif
                        <a href="{{ $item->directUrl }}" class="mt-4 inline-flex min-h-10 items-center text-sm font-bold text-slate-600 hover:text-emerald-700">{{ __('profiles.actions.open') }}</a>
                    </article>
                @endforeach
            </div>
            <nav class="mt-5" aria-label="{{ __('profiles.pagination') }}">{{ $items->links() }}</nav>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($items as $item)
                    @if ($item->url && $item->title)
                        <a href="{{ $item->url }}" class="flex min-w-0 gap-3 rounded-panel border border-slate-200 bg-white p-3 shadow-panel hover:border-emerald-200">
                            @if ($item->posterUrl)<x-ui.poster-frame :src="$item->posterUrl" alt="" fit="contain" :overscan="false" class="h-24 w-16 shrink-0 rounded-control" />@endif
                            <span class="min-w-0"><span class="block break-words font-black text-slate-900">{{ $item->title }}</span>@if ($item->year)<span class="mt-1 block text-xs font-bold text-slate-500">{{ $item->year }}</span>@endif</span>
                        </a>
                    @endif
                @endforeach
            </div>
            <nav class="mt-5" aria-label="{{ __('profiles.pagination') }}">{{ $items->links() }}</nav>
        @endif
    </section>

    @if ($reportOpen)
        <section role="dialog" aria-labelledby="profile-report-title" class="rounded-panel border border-amber-200 bg-amber-50 p-4 shadow-panel sm:p-5">
            <h2 id="profile-report-title" class="text-lg font-black text-slate-900">{{ __('profiles.reports.title') }}</h2>
            <form wire:submit="submitReport" class="mt-4 space-y-4">
                <label for="profile-report-category" class="block text-sm font-bold text-slate-700">{{ __('profiles.reports.category') }}</label>
                <select id="profile-report-category" wire:model="reportCategory" class="min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 text-sm text-slate-800">
                    @foreach ($reportCategories as $category)<option value="{{ $category['value'] }}">{{ $category['label'] }}</option>@endforeach
                </select>
                @error('reportCategory')<p class="text-sm font-semibold text-rose-700">{{ $message }}</p>@enderror
                <label for="profile-report-details" class="block text-sm font-bold text-slate-700">{{ __('profiles.reports.details') }}</label>
                <textarea id="profile-report-details" wire:model="reportDetails" rows="4" maxlength="{{ $reportMaximumLength }}" class="w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm leading-6 text-slate-800"></textarea>
                @error('reportDetails')<p class="text-sm font-semibold text-rose-700">{{ $message }}</p>@enderror
                <div class="flex flex-wrap gap-2">
                    <button type="submit" wire:loading.attr="disabled" wire:target="submitReport" class="inline-flex min-h-11 items-center justify-center rounded-control bg-amber-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-amber-700 disabled:opacity-60">{{ __('profiles.reports.submit') }}</button>
                    <button type="button" wire:click="closeReport" class="inline-flex min-h-11 items-center justify-center rounded-control bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-100">{{ __('profiles.reports.cancel') }}</button>
                </div>
            </form>
        </section>
    @endif
</div>
