<div class="mx-auto max-w-6xl space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
        <p class="text-xs font-black uppercase tracking-[0.16em] text-emerald-700">{{ __('premium.eyebrow') }}</p>
        <div class="mt-2 flex min-w-0 flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="break-words text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">{{ __('premium.pricing_title') }}</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('premium.pricing_description') }}</p>
            </div>
            @if ($settingsUrl !== null)
                <a href="{{ $settingsUrl }}" wire:navigate class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700">
                    <x-ui.icon name="fa-solid fa-crown" />{{ __('premium.manage_access') }}
                </a>
            @endif
        </div>
    </header>

    @if ($summary->active)
        <section aria-label="{{ __('premium.a11y.status') }}" class="rounded-panel border border-emerald-200 bg-emerald-50 p-4 sm:p-5">
            <div class="flex items-start gap-3">
                <x-ui.icon name="fa-solid fa-circle-check text-emerald-700" />
                <div class="min-w-0">
                    <h2 class="font-black text-emerald-900">{{ __('premium.current_access') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-emerald-800">
                        {{ $summaryMessage }}
                    </p>
                </div>
            </div>
        </section>
    @endif

    <p class="rounded-control border border-slate-200 bg-slate-50 p-4 text-sm font-semibold leading-6 text-slate-700">{{ __('premium.settings.regional_rules') }}</p>

    @if ($premiumHelp !== null)
        <a href="{{ $premiumHelp->url }}" wire:navigate class="inline-flex min-h-11 items-center gap-2 rounded-control border border-sky-200 bg-sky-50 px-4 py-2.5 text-sm font-black text-sky-900 hover:bg-sky-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700">
            <x-ui.icon name="fa-regular fa-circle-question" />
            <span>{{ $premiumHelp->title }}</span>
        </a>
    @endif

    @if ($plans === [])
        <section class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-7" aria-labelledby="premium-empty-title">
            <div class="flex min-w-0 items-start gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-slate-100 text-slate-600"><x-ui.icon name="fa-solid fa-credit-card" /></span>
                <div class="min-w-0">
                    <h2 id="premium-empty-title" class="break-words text-xl font-black text-slate-900">{{ __('premium.no_plans_title') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('premium.no_plans_description') }}</p>
                    <p class="mt-3 rounded-control bg-slate-50 p-3 text-sm font-semibold leading-6 text-slate-700">{{ __('premium.free_access_preserved') }}</p>
                </div>
            </div>
        </section>
    @else
        <form wire:submit="startCheckout" aria-label="{{ __('premium.a11y.plans') }}" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($plans as $plan)
                    <label class="flex min-w-0 cursor-pointer flex-col rounded-panel border bg-white p-5 shadow-panel transition focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-emerald-700 {{ $selectedPlan === $plan->code ? 'border-emerald-600' : 'border-slate-200' }}">
                        <span class="flex items-start gap-3">
                            <input type="radio" wire:model="selectedPlan" value="{{ $plan->code }}" class="mt-1 h-5 w-5 shrink-0 border-slate-300 text-emerald-700 focus:ring-emerald-600" />
                            <span class="min-w-0">
                                <span class="block break-words text-lg font-black text-slate-900">{{ $plan->name }}</span>
                                <span class="mt-1 block text-2xl font-black tabular-nums text-emerald-800">{{ $plan->price }}</span>
                            </span>
                        </span>
                        <span class="mt-4 block text-sm leading-6 text-slate-600">{{ $plan->description }}</span>
                        @if ($plan->durationDays !== null)<span class="mt-2 block text-sm font-bold text-slate-700">{{ trans_choice('premium.duration_days', $plan->durationDays, ['count' => $plan->durationDays]) }}</span>@endif
                        <span class="mt-2 block text-xs font-semibold leading-5 text-slate-500">{{ $plan->recurring ? __('premium.recurring_disclosure') : __('premium.one_time_disclosure') }}</span>
                        <ul class="mt-4 space-y-2">
                            @foreach ($plan->features as $feature)
                                <li class="flex items-start gap-2 text-sm text-slate-700"><x-ui.icon name="fa-solid fa-check mt-1 text-emerald-700" /><span>{{ $feature['label'] }}</span></li>
                            @endforeach
                        </ul>
                    </label>
                @endforeach
            </div>
            @error('selectedPlan')<p class="rounded-control border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-800" role="alert">{{ $message }}</p>@enderror
            <button type="submit" wire:loading.attr="disabled" wire:target="startCheckout" class="inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-5 py-3 text-sm font-black text-white hover:bg-emerald-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 disabled:cursor-wait disabled:opacity-60 sm:w-auto">
                <x-ui.icon name="fa-solid fa-lock" /><span wire:loading.remove wire:target="startCheckout">{{ $isAuthenticated ? __('premium.choose_plan') : __('premium.login_to_continue') }}</span><span wire:loading wire:target="startCheckout" role="status">{{ __('premium.a11y.loading') }}</span>
            </button>
            <p class="text-xs font-semibold leading-5 text-slate-500">{{ __('premium.secure_checkout') }}</p>
        </form>
    @endif
</div>
