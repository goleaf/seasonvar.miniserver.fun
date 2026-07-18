<div class="mx-auto max-w-4xl space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex min-w-0 items-start gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-emerald-50 text-emerald-700">
                    <x-ui.icon name="fa-solid fa-user" />
                </span>
                <div class="min-w-0">
                    <h1 class="break-words text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">{{ __('settings.profile_page.title') }}</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('settings.profile_page.description') }}</p>
                </div>
            </div>

            <x-ui.status-pill
                :icon="$emailVerified ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'"
                :variant="$emailVerified ? 'success' : 'warning'"
            >
                {{ $emailVerified ? __('settings.profile_page.email_verified') : __('settings.profile_page.email_unverified') }}
            </x-ui.status-pill>
        </div>

        @if ($createdAt)
            <p class="mt-4 text-xs font-semibold text-slate-500">{{ __('settings.profile_page.created_at', ['date' => $createdAt]) }}</p>
        @endif
    </header>

    <x-ui.panel :title="__('profiles.settings.title')" :subtitle="__('profiles.settings.description')" icon="fa-solid fa-id-card">
        @if ($status)
            <div class="mb-5">
                <x-form.status-message :message="$status" />
            </div>
        @endif
        @if ($profileActionError)
            <div role="alert" class="mb-5 rounded-control border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">
                {{ $profileActionError }}
            </div>
        @endif

        <div class="space-y-6">
            <div class="rounded-control border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ __('profiles.settings.public_url') }}</p>
                <a href="{{ $publicProfileUrl }}" class="mt-2 inline-flex min-h-10 max-w-full items-center gap-2 break-all text-sm font-bold text-emerald-700 hover:text-emerald-600"><x-ui.icon name="fa-solid fa-arrow-up-right-from-square" />{{ $publicProfileUrl }}</a>
            </div>

            <div class="grid gap-5 lg:grid-cols-2">
                <form wire:submit="saveUsername" class="space-y-4 rounded-panel border border-slate-200 p-4">
                    <h3 class="font-black text-slate-900">{{ __('profiles.settings.username') }}</h3>
                    <p class="text-sm leading-6 text-slate-600">{{ __('profiles.settings.username_hint') }}</p>
                    <x-form.field
                        :label="__('profiles.settings.username')"
                        for="public-profile-username"
                        wire:model="username"
                        autocomplete="username"
                        required
                    />
                    <x-form.password-field
                        :label="__('profiles.settings.username_password')"
                        for="public-profile-password"
                        wire:model="profilePassword"
                        autocomplete="current-password"
                        required
                    />
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveUsername" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800 disabled:opacity-60 sm:w-auto"><x-ui.icon name="fa-solid fa-at" />{{ __('profiles.settings.save_username') }}</button>
                </form>

                <form wire:submit="savePublicDetails" class="space-y-4 rounded-panel border border-slate-200 p-4">
                    <h3 class="font-black text-slate-900">{{ __('profiles.settings.biography') }}</h3>
                    <label for="public-profile-biography" class="block text-sm font-bold text-slate-700">{{ __('profiles.settings.biography') }}</label>
                    <textarea id="public-profile-biography" wire:model="biography" rows="7" maxlength="{{ $biographyMaximumLength }}" aria-describedby="public-profile-biography-hint" class="w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-base leading-6 text-slate-800 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100"></textarea>
                    <p id="public-profile-biography-hint" class="text-xs font-semibold leading-5 text-slate-500">{{ __('profiles.settings.biography_hint') }}</p>
                    @error('biography')<p class="text-sm font-semibold text-rose-700">{{ $message }}</p>@enderror
                    <button type="submit" wire:loading.attr="disabled" wire:target="savePublicDetails" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800 disabled:opacity-60 sm:w-auto"><x-ui.icon name="fa-solid fa-floppy-disk" />{{ __('profiles.settings.save_details') }}</button>
                </form>
            </div>

            <div class="grid gap-5 lg:grid-cols-2">
                <form wire:submit="saveAvatar" class="space-y-4 rounded-panel border border-slate-200 p-4">
                    <h3 class="font-black text-slate-900">{{ __('profiles.settings.avatar') }}</h3>
                    <div class="flex items-center gap-4">
                        @if ($avatarUrl)<img src="{{ $avatarUrl }}" alt="{{ __('profiles.accessibility.avatar', ['name' => $name]) }}" class="h-20 w-20 rounded-full object-cover">@endif
                        <label for="public-profile-avatar" class="min-w-0 flex-1 text-sm font-bold text-slate-700">{{ __('profiles.settings.avatar') }}
                            <input id="public-profile-avatar" type="file" wire:model="avatarUpload" accept="image/jpeg,image/png,image/webp" class="mt-2 block min-h-11 w-full min-w-0 text-sm font-normal text-slate-700 file:mr-3 file:min-h-11 file:rounded-control file:border-0 file:bg-slate-100 file:px-4 file:font-bold file:text-slate-700">
                        </label>
                    </div>
                    @error('avatarUpload')<p class="text-sm font-semibold text-rose-700">{{ $message }}</p>@enderror
                    <div class="flex flex-wrap gap-2">
                        <button type="submit" wire:loading.attr="disabled" wire:target="saveAvatar,avatarUpload" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800 disabled:opacity-60"><x-ui.icon name="fa-solid fa-upload" />{{ __('profiles.settings.upload') }}</button>
                        @if ($avatarUrl)<button type="button" wire:click="removeAvatar" wire:confirm="{{ __('profiles.settings.remove') }}?" wire:loading.attr="disabled" wire:target="removeAvatar" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-rose-50 px-4 py-2.5 text-sm font-bold text-rose-700 hover:bg-rose-100"><x-ui.icon name="fa-solid fa-trash" />{{ __('profiles.settings.remove') }}</button>@endif
                    </div>
                </form>

                <form wire:submit="saveCover" class="space-y-4 rounded-panel border border-slate-200 p-4">
                    <h3 class="font-black text-slate-900">{{ __('profiles.settings.cover') }}</h3>
                    @if ($coverUrl)<img src="{{ $coverUrl }}" alt="" class="h-24 w-full rounded-control object-cover">@endif
                    <label for="public-profile-cover" class="block text-sm font-bold text-slate-700">{{ __('profiles.settings.cover') }}
                        <input id="public-profile-cover" type="file" wire:model="coverUpload" accept="image/jpeg,image/png,image/webp" class="mt-2 block min-h-11 w-full text-sm font-normal text-slate-700 file:mr-3 file:min-h-11 file:rounded-control file:border-0 file:bg-slate-100 file:px-4 file:font-bold file:text-slate-700">
                    </label>
                    @error('coverUpload')<p class="text-sm font-semibold text-rose-700">{{ $message }}</p>@enderror
                    <div class="flex flex-wrap gap-2">
                        <button type="submit" wire:loading.attr="disabled" wire:target="saveCover,coverUpload" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800 disabled:opacity-60"><x-ui.icon name="fa-solid fa-upload" />{{ __('profiles.settings.upload') }}</button>
                        @if ($coverUrl)<button type="button" wire:click="removeCover" wire:confirm="{{ __('profiles.settings.remove') }}?" wire:loading.attr="disabled" wire:target="removeCover" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-rose-50 px-4 py-2.5 text-sm font-bold text-rose-700 hover:bg-rose-100"><x-ui.icon name="fa-solid fa-trash" />{{ __('profiles.settings.remove') }}</button>@endif
                    </div>
                </form>
            </div>

            <form wire:submit="saveProfilePrivacy" class="space-y-4 rounded-panel border border-slate-200 p-4">
                <div>
                    <h3 class="font-black text-slate-900">{{ __('profiles.settings.privacy_title') }}</h3>
                    <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('profiles.settings.privacy_hint') }}</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <label class="block text-sm font-bold text-slate-700">{{ __('profiles.settings.profile_visibility') }}
                        <select wire:model="profileVisibility" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 text-sm text-slate-800">
                            @foreach ($profileVisibilityOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach
                        </select>
                    </label>
                    @foreach ($profileSections as $section)
                        <label class="block text-sm font-bold text-slate-700">{{ $section['label'] }}
                            <select wire:model="sectionVisibility.{{ $section['key'] }}" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 text-sm text-slate-800">
                                @foreach ($profileVisibilityOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach
                            </select>
                        </label>
                    @endforeach
                </div>
                @error('profileVisibility')<p class="text-sm font-semibold text-rose-700">{{ $message }}</p>@enderror
                @error('sectionVisibility.*')<p class="text-sm font-semibold text-rose-700">{{ $message }}</p>@enderror
                <button type="submit" wire:loading.attr="disabled" wire:target="saveProfilePrivacy" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800 disabled:opacity-60 sm:w-auto"><x-ui.icon name="fa-solid fa-shield-halved" />{{ __('profiles.settings.save_privacy') }}</button>
            </form>
        </div>
    </x-ui.panel>

    <section aria-labelledby="library-summary-title" class="space-y-3">
        <div class="flex items-end justify-between gap-3">
            <div>
                <h2 id="library-summary-title" class="text-lg font-black text-slate-800">{{ __('settings.profile_page.library') }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ __('settings.profile_page.library_hint') }}</p>
            </div>
            <a href="{{ route('library.index') }}" class="text-sm font-bold text-emerald-700 hover:text-emerald-600">{{ __('settings.profile_page.open') }}</a>
        </div>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ([
                ['label' => __('settings.profile_page.watchlist'), 'count' => $librarySummary->watchlistCount, 'icon' => 'fa-solid fa-bookmark'],
                ['label' => __('settings.profile_page.ratings'), 'count' => $librarySummary->ratingsCount, 'icon' => 'fa-solid fa-star'],
                ['label' => __('settings.profile_page.continue_watching'), 'count' => $librarySummary->continueWatchingCount, 'icon' => 'fa-solid fa-circle-play'],
                ['label' => __('settings.profile_page.history'), 'count' => $librarySummary->historyCount, 'icon' => 'fa-solid fa-clock-rotate-left'],
            ] as $item)
                <div class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel">
                    <span class="text-emerald-700"><x-ui.icon :name="$item['icon']" /></span>
                    <p class="mt-3 text-2xl font-black text-slate-800">{{ $item['count'] }}</p>
                    <p class="mt-1 text-xs font-bold uppercase tracking-wide text-slate-500">{{ $item['label'] }}</p>
                </div>
            @endforeach
        </div>
    </section>

    <section aria-labelledby="collection-summary-title" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 id="collection-summary-title" class="flex items-center gap-2 text-lg font-black text-slate-800"><x-ui.icon name="fa-solid fa-layer-group text-emerald-700" />{{ __('collections.dashboard.title') }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ __('collections.dashboard.description') }}</p>
            </div>
            <a href="{{ route('collections.mine') }}" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-50 px-4 py-2.5 text-sm font-bold text-emerald-700 hover:bg-emerald-100 sm:w-auto"><x-ui.icon name="fa-solid fa-folder-open" />{{ __('collections.actions.manage') }}</a>
            <a href="{{ route('personal-tags.index') }}" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-violet-50 px-4 py-2.5 text-sm font-bold text-violet-700 hover:bg-violet-100 sm:w-auto"><x-ui.icon name="fa-solid fa-tags" />{{ __('tags.personal_page.title') }}</a>
        </div>
        <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ([
                ['label' => __('collections.account.total'), 'count' => $collectionSummary['total']],
                ['label' => __('collections.visibility.public'), 'count' => $collectionSummary['public']],
                ['label' => __('collections.visibility.unlisted'), 'count' => $collectionSummary['unlisted']],
                ['label' => __('collections.visibility.private'), 'count' => $collectionSummary['private']],
            ] as $collectionCount)
                <div class="rounded-control bg-slate-50 p-3">
                    <dt class="text-xs font-bold text-slate-500">{{ $collectionCount['label'] }}</dt>
                    <dd class="mt-1 text-xl font-black tabular-nums text-slate-800">{{ $collectionCount['count'] }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5" aria-labelledby="profile-discussions-title">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 id="profile-discussions-title" class="flex items-center gap-2 text-lg font-black text-slate-800"><x-ui.icon name="fa-solid fa-comments text-emerald-700" />{{ __('comments.profile.title') }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ __('comments.profile.description') }}</p>
            </div>
            <a href="{{ route('profile.discussions') }}" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-50 px-4 py-2.5 text-sm font-bold text-emerald-700 hover:bg-emerald-100 sm:w-auto"><x-ui.icon name="fa-solid fa-arrow-right" />{{ __('comments.navigation.discussions') }}</a>
        </div>
    </section>

    <section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5" aria-labelledby="profile-reviews-title">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 id="profile-reviews-title" class="flex items-center gap-2 text-lg font-black text-slate-800"><x-ui.icon name="fa-solid fa-star-half-stroke text-emerald-700" />{{ __('reviews.profile.title') }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ __('reviews.profile.description') }}</p>
            </div>
            <a href="{{ route('profile.reviews') }}" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-50 px-4 py-2.5 text-sm font-bold text-emerald-700 hover:bg-emerald-100 sm:w-auto"><x-ui.icon name="fa-solid fa-arrow-right" />{{ __('reviews.profile.open') }}</a>
        </div>
    </section>

    <x-ui.panel :title="__('settings.profile_page.account_data')" :subtitle="__('settings.profile_page.account_data_hint')" icon="fa-solid fa-address-card">
        <form wire:submit="saveProfile" class="space-y-5" novalidate>
            <x-form.field
                :label="__('settings.profile_page.name')"
                for="profile-name"
                wire:model="name"
                autocomplete="name"
                required
            />
            <x-form.field
                :label="__('settings.profile_page.email')"
                for="profile-email"
                type="email"
                wire:model="email"
                autocomplete="email"
                required
            />
            <x-form.password-field
                :label="__('settings.profile_page.current_password')"
                for="profile-current-password"
                wire:model="currentPassword"
                autocomplete="current-password"
                :hint="__('settings.profile_page.current_password_hint')"
            />

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="saveProfile"
                class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
            >
                <x-ui.icon name="fa-solid fa-floppy-disk" />
                <span wire:loading.remove wire:target="saveProfile">{{ __('settings.profile_page.save') }}</span>
                <span wire:loading wire:target="saveProfile">{{ __('settings.actions.saving') }}</span>
            </button>
        </form>
    </x-ui.panel>

    @unless ($emailVerified)
        <x-ui.panel :title="__('settings.profile_page.verify_email')" :subtitle="__('settings.profile_page.verify_email_hint')" icon="fa-solid fa-envelope-circle-check">
            <a href="{{ route('verification.notice') }}" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-50 px-4 py-2.5 text-sm font-bold text-emerald-700 hover:bg-emerald-100 sm:w-auto">
                <x-ui.icon name="fa-solid fa-paper-plane" />
                <span>{{ __('settings.profile_page.open_verification') }}</span>
            </a>
        </x-ui.panel>
    @endunless

    <nav aria-label="{{ __('settings.profile_page.account_sections') }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <a href="{{ route('profile.security') }}" class="flex min-h-11 items-center justify-between gap-3 rounded-panel border border-slate-200 bg-white p-4 font-bold text-slate-700 shadow-panel hover:border-emerald-200 hover:text-emerald-700">
            <span class="inline-flex items-center gap-2">
                <x-ui.icon name="fa-solid fa-shield-halved" />
                {{ __('settings.navigation.security') }}
            </span>
            <x-ui.icon name="fa-solid fa-chevron-right text-xs" />
        </a>
        <a href="{{ route('library.index') }}" class="flex min-h-11 items-center justify-between gap-3 rounded-panel border border-slate-200 bg-white p-4 font-bold text-slate-700 shadow-panel hover:border-emerald-200 hover:text-emerald-700">
            <span class="inline-flex items-center gap-2">
                <x-ui.icon name="fa-solid fa-bookmark" />
                {{ __('settings.profile_page.library') }}
            </span>
            <x-ui.icon name="fa-solid fa-chevron-right text-xs" />
        </a>
        <a href="{{ route('collections.mine') }}" class="flex min-h-11 items-center justify-between gap-3 rounded-panel border border-slate-200 bg-white p-4 font-bold text-slate-700 shadow-panel hover:border-emerald-200 hover:text-emerald-700">
            <span class="inline-flex items-center gap-2">
                <x-ui.icon name="fa-solid fa-layer-group" />
                {{ __('collections.navigation.my_collections') }}
            </span>
            <x-ui.icon name="fa-solid fa-chevron-right text-xs" />
        </a>
        <a href="{{ route('profile.reviews') }}" class="flex min-h-11 items-center justify-between gap-3 rounded-panel border border-slate-200 bg-white p-4 font-bold text-slate-700 shadow-panel hover:border-emerald-200 hover:text-emerald-700">
            <span class="inline-flex items-center gap-2">
                <x-ui.icon name="fa-solid fa-star-half-stroke" />
                {{ __('reviews.navigation.my_reviews') }}
            </span>
            <x-ui.icon name="fa-solid fa-chevron-right text-xs" />
        </a>
    </nav>
</div>
