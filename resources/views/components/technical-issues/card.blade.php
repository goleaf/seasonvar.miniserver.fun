@props(['issue', 'staff' => false, 'selectable' => false, 'selected' => false])

<article class="min-w-0 rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5" wire:key="technical-issue-{{ $issue->publicId }}">
    <div class="flex min-w-0 flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2 text-xs font-bold">
                @if ($selectable)
                    <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-control border border-slate-200 px-3">
                        <input type="checkbox" wire:model="selectedIssues" value="{{ $issue->id }}" @checked($selected) class="size-5 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                        <span>{{ __('issues.admin.select') }}</span>
                    </label>
                @endif
                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-800">{{ $issue->typeLabel }}</span>
                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">{{ $issue->statusLabel }}</span>
                @if ($issue->needsRequesterResponse)
                    <span class="rounded-full bg-amber-50 px-2.5 py-1 text-amber-900">{{ __('issues.mine.needs_response') }}</span>
                @endif
            </div>
            <h2 class="mt-3 break-words text-lg font-black text-slate-900">
                <a href="{{ $issue->url }}" class="rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2">{{ $issue->number }}</a>
            </h2>
            <p class="mt-1 break-words text-sm font-bold text-slate-700">{{ $issue->targetLabel }}</p>
            @if ($issue->summary)
                <p class="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-slate-600">{{ $issue->summary }}</p>
            @endif
            @if ($staff && $issue->requesterName)
                <p class="mt-2 text-xs text-slate-500">{{ __('issues.admin.requester') }}: {{ $issue->requesterName }}</p>
            @endif
        </div>
        @if ($staff)
            <dl class="grid shrink-0 gap-2 text-right text-xs">
                <div><dt class="text-slate-500">{{ __('issues.fields.severity') }}</dt><dd class="font-bold text-slate-800">{{ $issue->severityLabel }}</dd></div>
                <div><dt class="text-slate-500">{{ __('issues.fields.priority') }}</dt><dd class="font-bold text-slate-800">{{ $issue->priorityLabel }}</dd></div>
                <div><dt class="text-slate-500">{{ __('issues.fields.assignee') }}</dt><dd class="font-bold text-slate-800">{{ $issue->isAssigned ? __('issues.admin.assigned') : __('issues.admin.unassigned') }}</dd></div>
                @if ($issue->sourceHealth)<div><dt class="text-slate-500">{{ __('issues.diagnostics.source_health') }}</dt><dd class="font-bold text-slate-800">{{ __('issues.source_health.'.$issue->sourceHealth) }}</dd></div>@endif
            </dl>
        @endif
    </div>
    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4 text-sm">
        <div class="flex flex-wrap items-center gap-4 text-slate-600">
            @if ($staff)
                <span aria-label="{{ __('issues.card.affected', ['count' => $issue->affectedUserCount]) }}"><x-ui.icon name="fa-solid fa-users" /> {{ $issue->affectedUserCount }}</span>
            @else
                <span aria-label="{{ __('issues.card.confirmations', ['count' => $issue->confirmationCount]) }}"><x-ui.icon name="fa-solid fa-users" /> {{ $issue->confirmationCount }}</span>
            @endif
            @if ($issue->attachmentCount > 0)<span aria-label="{{ __('issues.card.attachments', ['count' => $issue->attachmentCount]) }}"><x-ui.icon name="fa-solid fa-paperclip" /> {{ $issue->attachmentCount }}</span>@endif
            @if ($issue->messageCount > 0)<span aria-label="{{ __('issues.card.messages', ['count' => $issue->messageCount]) }}"><x-ui.icon name="fa-solid fa-comments" /> {{ $issue->messageCount }}</span>@endif
            <time>{{ $issue->updatedAt }}</time>
        </div>
        <a href="{{ $issue->url }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-200">
            {{ __('issues.actions.open_ticket') }} <x-ui.icon name="fa-solid fa-arrow-right" />
        </a>
    </div>
</article>
