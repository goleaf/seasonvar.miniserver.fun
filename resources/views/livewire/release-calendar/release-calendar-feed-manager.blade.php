<section data-calendar-feed-manager aria-labelledby="calendar-feed-manager-title" class="rounded-panel border border-emerald-200 bg-white p-4 shadow-panel sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-xs font-black uppercase tracking-[0.16em] text-emerald-700">{{ __('calendar.feeds.eyebrow') }}</p>
            <h2 id="calendar-feed-manager-title" class="mt-2 text-xl font-black text-slate-900">{{ __('calendar.feeds.title') }}</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('calendar.feeds.description') }}</p>
        </div>
        <div class="rounded-control bg-amber-50 px-3 py-2 text-xs font-bold leading-5 text-amber-900">
            <x-ui.icon name="fa-solid fa-lock mr-1" />{{ __('calendar.feeds.private_notice') }}
        </div>
    </div>

    @if (! $feedsReady)
        <div class="mt-5 rounded-control border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="status">
            {{ __('calendar.feeds.unavailable') }}
        </div>
    @else
        @if ($notice !== '')
            <div class="mt-5 rounded-control border border-emerald-200 bg-emerald-50 p-4 text-sm font-bold text-emerald-800" role="status" aria-live="polite">
                {{ $notice }}
            </div>
        @endif

        <form wire:submit="createFeed" class="mt-5 rounded-panel border border-slate-200 bg-slate-50 p-4">
            <div class="grid gap-4 lg:grid-cols-2">
                <label class="text-sm font-bold text-slate-700">
                    <span>{{ __('calendar.feeds.scope_label') }}</span>
                    <select wire:model.live="scope" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-slate-900">
                        @foreach ($scopeOptions as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </label>

                @if ($scope === 'collection')
                    <label class="text-sm font-bold text-slate-700">
                        <span>{{ __('calendar.feeds.collection_label') }}</span>
                        <select wire:model="collectionPublicId" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-slate-900">
                            <option value="">{{ __('calendar.feeds.collection_placeholder') }}</option>
                            @foreach ($collections as $collection)
                                <option value="{{ $collection->public_id }}">{{ $collection->display_name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                @if (in_array($scope, ['title', 'translation', 'subtitles'], true))
                    <div class="relative text-sm font-bold text-slate-700">
                        <label for="calendar-feed-title-search">{{ __('calendar.feeds.title_label') }}</label>
                        @if ($scope !== 'title')
                            <span class="ml-1 text-xs font-medium text-slate-500">{{ __('calendar.feeds.optional') }}</span>
                        @endif
                        <div class="mt-2 flex gap-2">
                            <input id="calendar-feed-title-search" type="search" wire:model.live.debounce.300ms="titleSearch" autocomplete="off" placeholder="{{ __('calendar.feeds.title_placeholder') }}" class="min-h-11 min-w-0 flex-1 rounded-control border border-slate-300 bg-white px-3 py-2 text-slate-900" />
                            @if ($selectedTitle)
                                <button type="button" wire:click="clearTitle" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control bg-slate-200 text-slate-700 hover:bg-slate-300" aria-label="{{ __('calendar.feeds.clear_title') }}">
                                    <x-ui.icon name="fa-solid fa-xmark" />
                                </button>
                            @endif
                        </div>
                        @if ($selectedTitle)
                            <p class="mt-2 rounded-control bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                                <x-ui.icon name="fa-solid fa-check mr-1" />{{ $selectedTitle->display_title }}
                            </p>
                        @elseif ($titleSuggestions->isNotEmpty())
                            <ul class="absolute z-20 mt-1 w-full rounded-control border border-slate-200 bg-white p-1 shadow-elevated" aria-label="{{ __('calendar.feeds.title_results') }}">
                                @foreach ($titleSuggestions as $title)
                                    <li>
                                        <button type="button" wire:click="selectTitle({{ $title->id }})" class="flex min-h-11 w-full items-center justify-between gap-3 rounded-control px-3 py-2 text-left text-sm text-slate-800 hover:bg-emerald-50">
                                            <span>{{ $title->display_title }}</span>
                                            @if ($title->year)<span class="shrink-0 text-xs text-slate-500">{{ $title->year }}</span>@endif
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif

                @if ($scope === 'translation')
                    <label class="text-sm font-bold text-slate-700">
                        <span>{{ __('calendar.feeds.translation_label') }}</span>
                        <input type="text" wire:model="translationName" maxlength="120" placeholder="{{ __('calendar.feeds.translation_placeholder') }}" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-slate-900" />
                    </label>
                @endif

                @if (in_array($scope, ['translation', 'subtitles'], true))
                    <label class="text-sm font-bold text-slate-700">
                        <span>{{ __('calendar.feeds.language_label') }}</span>
                        @if ($scope === 'translation')<span class="ml-1 text-xs font-medium text-slate-500">{{ __('calendar.feeds.optional') }}</span>@endif
                        <input type="text" wire:model="languageCode" maxlength="16" placeholder="{{ __('calendar.feeds.language_placeholder') }}" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-slate-900" />
                    </label>
                @endif
            </div>

            @error('scope')<p class="mt-3 text-sm font-bold text-rose-700" role="alert">{{ $message }}</p>@enderror
            @error('collectionPublicId')<p class="mt-3 text-sm font-bold text-rose-700" role="alert">{{ $message }}</p>@enderror
            @error('feedCollection')<p class="mt-3 text-sm font-bold text-rose-700" role="alert">{{ $message }}</p>@enderror
            @error('selectedTitleId')<p class="mt-3 text-sm font-bold text-rose-700" role="alert">{{ $message }}</p>@enderror
            @error('feedScope')<p class="mt-3 text-sm font-bold text-rose-700" role="alert">{{ $message }}</p>@enderror
            @error('languageCode')<p class="mt-3 text-sm font-bold text-rose-700" role="alert">{{ $message }}</p>@enderror
            @error('feedLanguageCode')<p class="mt-3 text-sm font-bold text-rose-700" role="alert">{{ $message }}</p>@enderror
            @error('translationName')<p class="mt-3 text-sm font-bold text-rose-700" role="alert">{{ $message }}</p>@enderror

            <button type="submit" wire:loading.attr="disabled" wire:target="createFeed" class="mt-4 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-60 sm:w-auto">
                <x-ui.icon name="fa-solid fa-plus" />
                <span wire:loading.remove wire:target="createFeed">{{ __('calendar.feeds.create') }}</span>
                <span wire:loading wire:target="createFeed">{{ __('calendar.feeds.creating') }}</span>
            </button>
        </form>

        <div class="mt-6">
            <h3 class="text-base font-black text-slate-900">{{ __('calendar.feeds.existing_title') }}</h3>
            @if ($feedRows === [])
                <p class="mt-3 rounded-control border border-dashed border-slate-300 p-4 text-sm text-slate-600">{{ __('calendar.feeds.empty') }}</p>
            @else
                <ul class="mt-3 grid gap-4">
                    @foreach ($feedRows as $feed)
                        <li wire:key="calendar-feed-{{ $feed['publicId'] }}" class="rounded-panel border border-slate-200 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h4 class="font-black text-slate-900">{{ $feed['label'] }}</h4>
                                    @if ($feed['details'] !== '')<p class="mt-1 text-sm text-slate-600">{{ $feed['details'] }}</p>@endif
                                    <p class="mt-1 text-xs text-slate-500">{{ __('calendar.feeds.rotated_at', ['date' => $feed['rotatedAt']]) }}</p>
                                </div>
                            </div>

                            <label class="mt-3 block text-xs font-bold text-slate-600">
                                <span>{{ __('calendar.feeds.private_url') }}</span>
                                <input type="text" readonly value="{{ $feed['privateUrl'] }}" class="mt-1 min-h-11 w-full rounded-control border border-slate-300 bg-slate-50 px-3 py-2 font-mono text-xs text-slate-700" />
                            </label>

                            <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                <button type="button" data-calendar-copy data-calendar-url="{{ $feed['privateUrl'] }}" data-copy-success="{{ __('calendar.feeds.copied') }}" data-copy-error="{{ __('calendar.feeds.copy_failed') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-100 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-200">
                                    <x-ui.icon name="fa-regular fa-copy" /><span data-calendar-copy-label>{{ __('calendar.feeds.copy') }}</span>
                                </button>
                                <a href="{{ $feed['googleUrl'] }}" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" data-calendar-google data-calendar-url="{{ $feed['privateUrl'] }}" data-copy-success="{{ __('calendar.feeds.google_copied') }}" data-copy-error="{{ __('calendar.feeds.copy_failed') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-blue-50 px-3 py-2 text-sm font-bold text-blue-800 hover:bg-blue-100">
                                    <x-ui.icon name="fa-brands fa-google" /><span data-calendar-copy-label>{{ __('calendar.feeds.google') }}</span>
                                </a>
                                <a href="{{ $feed['appleUrl'] }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-100 px-3 py-2 text-sm font-bold text-slate-800 hover:bg-slate-200">
                                    <x-ui.icon name="fa-brands fa-apple" />{{ __('calendar.feeds.apple') }}
                                </a>
                            </div>

                            <div class="mt-3 flex flex-wrap gap-2 border-t border-slate-100 pt-3">
                                <button type="button" wire:click="regenerateFeed('{{ $feed['publicId'] }}')" wire:confirm="{{ __('calendar.feeds.regenerate_confirm') }}" wire:loading.attr="disabled" wire:target="regenerateFeed('{{ $feed['publicId'] }}')" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-amber-50 px-3 py-2 text-sm font-bold text-amber-900 hover:bg-amber-100 disabled:opacity-60">
                                    <x-ui.icon name="fa-solid fa-rotate" />{{ __('calendar.feeds.regenerate') }}
                                </button>
                                <button type="button" wire:click="deleteFeed('{{ $feed['publicId'] }}')" wire:confirm="{{ __('calendar.feeds.delete_confirm') }}" wire:loading.attr="disabled" wire:target="deleteFeed('{{ $feed['publicId'] }}')" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-rose-50 px-3 py-2 text-sm font-bold text-rose-700 hover:bg-rose-100 disabled:opacity-60">
                                    <x-ui.icon name="fa-solid fa-trash-can" />{{ __('calendar.feeds.delete') }}
                                </button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</section>
