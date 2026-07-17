<div class="mx-auto max-w-5xl space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
        <div class="flex min-w-0 items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-emerald-50 text-emerald-700"><x-ui.icon name="fa-solid fa-comments" /></span>
            <div class="min-w-0">
                <h1 class="break-words text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">{{ __('comments.profile.title') }}</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('comments.profile.description') }}</p>
            </div>
        </div>
    </header>

    <div aria-live="polite" aria-atomic="true">
        @if ($notice !== null)
            <div class="rounded-control border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800" role="status"><x-ui.icon name="fa-solid fa-circle-check" /> {{ $notice }}</div>
        @endif
        @if ($actionError !== null)
            <div class="rounded-control border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-800" role="alert"><x-ui.icon name="fa-solid fa-circle-exclamation" /> {{ $actionError }}</div>
        @endif
    </div>

    <livewire:technical-issues.technical-issue-notifications-panel />

    @if (! $available)
        <div class="rounded-panel border border-slate-200 bg-white p-6 text-center shadow-panel">
            <x-ui.icon name="fa-solid fa-comment-slash text-2xl text-slate-400" />
            <p class="mt-3 text-sm font-semibold text-slate-600">{{ __('comments.states.comments_disabled') }}</p>
        </div>
    @elseif ($queryFailed)
        <div class="rounded-panel border border-rose-200 bg-white p-6 text-center shadow-panel" role="alert">
            <x-ui.icon name="fa-solid fa-triangle-exclamation text-2xl text-rose-600" />
            <p class="mt-3 text-sm font-semibold text-slate-700">{{ __('comments.states.query_failed') }}</p>
        </div>
    @else
        <section aria-labelledby="discussion-activity-title" class="rounded-panel border border-slate-200 bg-white shadow-panel">
            <div class="border-b border-slate-200 p-4 sm:p-5">
                <h2 id="discussion-activity-title" class="flex items-center gap-2 text-lg font-black text-slate-800"><x-ui.icon name="fa-solid fa-message text-emerald-700" />{{ __('comments.profile.activity') }}</h2>
            </div>
            @if ($activity->isEmpty())
                <p class="p-6 text-sm font-semibold text-slate-600">{{ __('comments.profile.activity_empty') }}</p>
            @else
                <ol class="divide-y divide-slate-200">
                    @foreach ($activity as $item)
                        <li wire:key="profile-comment-{{ $item->id }}" class="min-w-0 p-4 sm:p-5">
                            <div class="flex min-w-0 flex-wrap items-center gap-2 text-xs font-bold text-slate-500">
                                <x-ui.status-pill variant="muted">{{ $item->targetLabel }}</x-ui.status-pill>
                                <x-ui.status-pill :variant="$item->statusVariant">{{ $item->statusLabel }}</x-ui.status-pill>
                                <time datetime="{{ $item->createdAtIso }}">{{ $item->createdAtLabel }}</time>
                                @if ($item->editedAtLabel !== null)<span>{{ __('comments.states.edited', ['time' => $item->editedAtLabel]) }}</span>@endif
                            </div>
                            @if ($item->isSpoiler)
                                <p class="mt-3 rounded-control bg-amber-50 px-3 py-2 text-sm font-bold text-amber-900"><x-ui.icon name="fa-solid fa-triangle-exclamation" /> {{ __('comments.spoiler.warning') }}</p>
                            @elseif ($item->excerpt !== null)
                                <p class="mt-3 whitespace-pre-line break-words text-sm leading-6 text-slate-700 [overflow-wrap:anywhere]">{{ $item->excerpt }}</p>
                            @endif
                            <a href="{{ $item->directUrl }}" class="mt-3 inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 text-sm font-bold text-emerald-700 hover:bg-emerald-50"><x-ui.icon name="fa-solid fa-arrow-up-right-from-square" />{{ __('comments.actions.back_to_target') }}</a>
                        </li>
                    @endforeach
                </ol>
                @if ($activity->hasPages())
                    <nav class="p-4" aria-label="{{ __('comments.accessibility.pagination') }}">{{ $activity->links() }}</nav>
                @endif
            @endif
        </section>

        <section aria-labelledby="discussion-notifications-title" class="rounded-panel border border-slate-200 bg-white shadow-panel">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4 sm:p-5">
                <h2 id="discussion-notifications-title" class="flex items-center gap-2 text-lg font-black text-slate-800"><x-ui.icon name="fa-solid fa-bell text-emerald-700" />{{ __('comments.notifications.title') }}</h2>
                @if ($notificationsAvailable)
                    <button type="button" wire:click="markAllNotificationsRead" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 text-sm font-bold text-slate-700 hover:bg-slate-100"><x-ui.icon name="fa-solid fa-check-double" />{{ __('comments.notifications.mark_all_read') }}</button>
                @endif
            </div>
            @if (! $notificationsAvailable || $notifications->isEmpty())
                <p class="p-6 text-sm font-semibold text-slate-600">{{ __('comments.notifications.empty') }}</p>
            @else
                <ol class="divide-y divide-slate-200">
                    @foreach ($notifications as $notification)
                        <li wire:key="comment-notification-{{ $notification->id }}" @class(['p-4 sm:p-5', 'bg-emerald-50/60' => ! $notification->isRead])>
                            <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if (! $notification->isRead)<x-ui.status-pill variant="success">{{ __('comments.notifications.unread') }}</x-ui.status-pill>@endif
                                        <time datetime="{{ $notification->createdAtIso }}" class="text-xs font-semibold text-slate-500">{{ $notification->createdAtLabel }}</time>
                                    </div>
                                    <p class="mt-2 break-words text-sm font-bold text-slate-800">{{ $notification->label }}</p>
                                    @if ($notification->detail !== null)<p class="mt-1 text-sm text-slate-600">{{ $notification->detail }}</p>@endif
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    @if ($notification->url !== null)
                                        <a href="{{ $notification->url }}" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-emerald-700 px-3 text-sm font-bold text-white hover:bg-emerald-600 sm:flex-none"><x-ui.icon name="fa-solid fa-arrow-up-right-from-square" />{{ __('comments.actions.back_to_target') }}</a>
                                    @endif
                                    @if (! $notification->isRead)
                                        <button type="button" wire:click="markNotificationRead('{{ $notification->id }}')" wire:loading.attr="disabled" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-slate-50 px-3 text-sm font-bold text-slate-700 hover:bg-slate-100 sm:flex-none"><x-ui.icon name="fa-solid fa-check" />{{ __('comments.notifications.mark_read') }}</button>
                                    @endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ol>
                @if ($notifications->hasPages())
                    <nav class="p-4" aria-label="{{ __('comments.notifications.title') }}">{{ $notifications->links() }}</nav>
                @endif
            @endif
        </section>

        <section aria-labelledby="review-notifications-title" class="rounded-panel border border-slate-200 bg-white shadow-panel">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4 sm:p-5">
                <h2 id="review-notifications-title" class="flex items-center gap-2 text-lg font-black text-slate-800"><x-ui.icon name="fa-solid fa-star-half-stroke text-emerald-700" />{{ __('reviews.notifications.title') }}</h2>
                @if ($reviewNotificationsAvailable && ! $reviewNotificationsFailed)
                    <button type="button" wire:click="markAllReviewNotificationsRead" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 text-sm font-bold text-slate-700 hover:bg-slate-100"><x-ui.icon name="fa-solid fa-check-double" />{{ __('reviews.notifications.mark_all_read') }}</button>
                @endif
            </div>
            @if ($reviewNotificationsFailed)
                <p class="p-6 text-sm font-semibold text-rose-700" role="alert">{{ __('comments.states.query_failed') }}</p>
            @elseif (! $reviewNotificationsAvailable || $reviewNotifications === null || $reviewNotifications->isEmpty())
                <p class="p-6 text-sm font-semibold text-slate-600">{{ __('reviews.notifications.empty') }}</p>
            @else
                <ol class="divide-y divide-slate-200">
                    @foreach ($reviewNotifications as $notification)
                        <li wire:key="review-notification-{{ $notification->id }}" @class(['p-4 sm:p-5', 'bg-emerald-50/60' => ! $notification->isRead])>
                            <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if (! $notification->isRead)<x-ui.status-pill variant="success">{{ __('reviews.notifications.unread') }}</x-ui.status-pill>@endif
                                        <time datetime="{{ $notification->createdAtIso }}" class="text-xs font-semibold text-slate-500">{{ $notification->createdAtLabel }}</time>
                                    </div>
                                    <p class="mt-2 break-words text-sm font-bold text-slate-800">{{ $notification->label }}</p>
                                    @if ($notification->detail !== null)<p class="mt-1 text-sm text-slate-600">{{ $notification->detail }}</p>@endif
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    @if ($notification->url !== null)
                                        <a href="{{ $notification->url }}" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-emerald-700 px-3 text-sm font-bold text-white hover:bg-emerald-600 sm:flex-none"><x-ui.icon name="fa-solid fa-arrow-up-right-from-square" />{{ __('reviews.notifications.open') }}</a>
                                    @endif
                                    @if (! $notification->isRead)
                                        <button type="button" wire:click="markReviewNotificationRead('{{ $notification->id }}')" wire:loading.attr="disabled" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-slate-50 px-3 text-sm font-bold text-slate-700 hover:bg-slate-100 sm:flex-none"><x-ui.icon name="fa-solid fa-check" />{{ __('reviews.notifications.mark_read') }}</button>
                                    @endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ol>
                @if ($reviewNotifications->hasPages())
                    <nav class="p-4" aria-label="{{ __('reviews.notifications.title') }}">{{ $reviewNotifications->links() }}</nav>
                @endif
            @endif
        </section>

        <section aria-labelledby="request-notifications-title" class="rounded-panel border border-slate-200 bg-white shadow-panel">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4 sm:p-5">
                <h2 id="request-notifications-title" class="flex items-center gap-2 text-lg font-black text-slate-800"><x-ui.icon name="fa-solid fa-list-check text-emerald-700" />{{ __('requests.notifications.title') }}</h2>
                @if ($requestNotificationsAvailable)<button type="button" wire:click="markAllRequestNotificationsRead" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 text-sm font-bold text-slate-700"><x-ui.icon name="fa-solid fa-check-double" />{{ __('requests.notifications.mark_all_read') }}</button>@endif
            </div>
            @if (! $requestNotificationsAvailable || $requestNotificationsFailed || $requestNotifications === null || $requestNotifications->isEmpty())
                <p class="p-6 text-sm font-semibold text-slate-600">{{ $requestNotificationsFailed ? __('requests.errors.query_failed') : __('requests.notifications.empty') }}</p>
            @else
                <ul class="divide-y divide-slate-100">@foreach ($requestNotifications as $notification)<li wire:key="request-notification-{{ $notification->id }}" @class(['p-4 sm:p-5', 'bg-emerald-50/60' => ! $notification->isRead])><div class="flex min-w-0 flex-wrap items-start justify-between gap-3"><div class="min-w-0"><div class="flex flex-wrap items-center gap-2">@if (! $notification->isRead)<x-ui.status-pill variant="success">{{ __('requests.notifications.unread') }}</x-ui.status-pill>@endif<time datetime="{{ $notification->createdAtIso }}" class="text-xs text-slate-500">{{ $notification->createdAtLabel }}</time></div><p class="mt-2 break-words text-sm font-bold text-slate-800">{{ $notification->label }}</p>@if ($notification->detail)<p class="mt-1 text-sm text-slate-600">{{ $notification->detail }}</p>@endif</div><div class="flex flex-wrap gap-2">@if ($notification->url)<a href="{{ $notification->url }}" class="inline-flex min-h-11 items-center rounded-control bg-emerald-700 px-3 text-sm font-bold text-white">{{ __('requests.notifications.open') }}</a>@endif @if (! $notification->isRead)<button type="button" wire:click="markRequestNotificationRead('{{ $notification->id }}')" class="min-h-11 rounded-control bg-slate-100 px-3 text-sm font-bold text-slate-700">{{ __('requests.notifications.mark_read') }}</button>@endif</div></div></li>@endforeach</ul>
                @if ($requestNotifications->hasPages())<nav class="p-4" aria-label="{{ __('requests.notifications.title') }}">{{ $requestNotifications->links() }}</nav>@endif
            @endif
        </section>

        <livewire:release-calendar.release-calendar-notifications-panel />

        @if ($notificationsAvailable)
            <section aria-labelledby="discussion-preferences-title" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
                <h2 id="discussion-preferences-title" class="flex items-center gap-2 text-lg font-black text-slate-800"><x-ui.icon name="fa-solid fa-sliders text-emerald-700" />{{ __('comments.notifications.preferences') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('settings.notifications.description') }}</p>
                <a href="{{ route('settings.index', ['section' => 'notifications']) }}" wire:navigate class="mt-4 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 sm:w-auto"><x-ui.icon name="fa-solid fa-gear" />{{ __('settings.navigation.notifications') }}</a>
            </section>
        @endif

        <section aria-labelledby="discussion-privacy-title" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
            <h2 id="discussion-privacy-title" class="flex items-center gap-2 text-lg font-black text-slate-800"><x-ui.icon name="fa-solid fa-user-shield text-emerald-700" />{{ __('comments.profile.privacy') }}</h2>
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <div>
                    <h3 class="text-sm font-black text-slate-700">{{ __('comments.profile.blocks') }}</h3>
                    @if ($blocks === null || $blocks->isEmpty())
                        <p class="mt-2 rounded-control bg-slate-50 p-3 text-sm text-slate-600">{{ __('comments.profile.blocks_empty') }}</p>
                    @else
                        <ul class="mt-2 space-y-2">
                            @foreach ($blocks as $block)
                                <li wire:key="comment-block-{{ $block->userId }}" class="flex min-w-0 flex-wrap items-center justify-between gap-3 rounded-control bg-slate-50 p-3">
                                    <div class="min-w-0"><p class="break-words text-sm font-bold text-slate-700">{{ $block->name }}</p><p class="text-xs text-slate-500">{{ $block->createdAtLabel }}</p></div>
                                    <button type="button" wire:click="unblock({{ $block->userId }})" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-3 text-sm font-bold text-emerald-700 hover:bg-emerald-50"><x-ui.icon name="fa-solid fa-unlock" />{{ __('comments.actions.unblock') }}</button>
                                </li>
                            @endforeach
                        </ul>
                        @if ($blocks->hasPages())
                            <nav class="mt-3" aria-label="{{ __('comments.profile.blocks') }}">{{ $blocks->links() }}</nav>
                        @endif
                    @endif
                </div>
                <div>
                    <h3 class="text-sm font-black text-slate-700">{{ __('comments.profile.mutes') }}</h3>
                    @if ($mutes === null || $mutes->isEmpty())
                        <p class="mt-2 rounded-control bg-slate-50 p-3 text-sm text-slate-600">{{ __('comments.profile.mutes_empty') }}</p>
                    @else
                        <ul class="mt-2 space-y-2">
                            @foreach ($mutes as $mute)
                                <li wire:key="comment-mute-{{ $mute->userId }}" class="flex min-w-0 flex-wrap items-center justify-between gap-3 rounded-control bg-slate-50 p-3">
                                    <div class="min-w-0"><p class="break-words text-sm font-bold text-slate-700">{{ $mute->name }}</p><p class="text-xs text-slate-500">{{ $mute->createdAtLabel }}</p></div>
                                    <button type="button" wire:click="unmute({{ $mute->userId }})" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-3 text-sm font-bold text-emerald-700 hover:bg-emerald-50"><x-ui.icon name="fa-solid fa-volume-high" />{{ __('comments.actions.unmute') }}</button>
                                </li>
                            @endforeach
                        </ul>
                        @if ($mutes->hasPages())
                            <nav class="mt-3" aria-label="{{ __('comments.profile.mutes') }}">{{ $mutes->links() }}</nav>
                        @endif
                    @endif
                </div>
            </div>
        </section>

        <section class="rounded-panel border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-600">
            <p>{{ __('comments.profile.export_hint') }}</p>
            <p class="mt-2">{{ __('comments.profile.deletion_hint') }}</p>
        </section>
    @endif
</div>
