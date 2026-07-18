<div class="mx-auto max-w-7xl space-y-5">
    @if ($article->usesFallback)
        <div class="rounded-panel border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950" role="status">
            <strong class="block font-black">{{ __('help.article.fallback_title') }}</strong>
            <span>{{ __('help.article.fallback', ['locale' => strtoupper($article->locale)]) }}</span>
        </div>
    @endif

    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]">
        <main class="min-w-0 space-y-5">
            <article class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-7 lg:p-9">
                <header class="border-b border-slate-100 pb-5">
                    <div class="flex flex-wrap items-center gap-2 text-xs font-black uppercase tracking-wide text-slate-500">
                        <span class="rounded-full bg-slate-100 px-2.5 py-1">{{ $article->typeLabel }}</span>
                        <a href="{{ $article->categoryUrl }}" class="rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-800 hover:underline">{{ $article->categoryTitle }}</a>
                    </div>
                    <h1 class="mt-4 break-words text-3xl font-black leading-tight tracking-tight text-slate-950 sm:text-4xl">{{ $article->title }}</h1>
                    <p class="mt-3 max-w-3xl text-base leading-7 text-slate-600">{{ $article->summary }}</p>
                    @if ($article->lastReviewedLabel !== null)
                        <p class="mt-4 text-xs font-bold text-slate-500">{{ __('help.article.last_reviewed', ['date' => $article->lastReviewedLabel]) }}</p>
                    @endif
                </header>

                @if ($article->calloutText !== null)
                    <aside @class([
                        'mt-5 rounded-control border p-4 text-sm leading-6',
                        'border-rose-200 bg-rose-50 text-rose-950' => in_array($article->calloutType, ['warning', 'security'], true),
                        'border-amber-200 bg-amber-50 text-amber-950' => $article->calloutType === 'limitation',
                        'border-sky-200 bg-sky-50 text-sky-950' => ! in_array($article->calloutType, ['warning', 'security', 'limitation'], true),
                    ])>
                        <strong class="block font-black">{{ __('help.callouts.'.($article->calloutType ?? 'information')) }}</strong>
                        <span>{{ $article->calloutText }}</span>
                    </aside>
                @endif

                @if ($article->faqPresentation && $article->content->faqItems !== [])
                    <section class="mt-6 space-y-3" aria-label="{{ __('help.article.faq') }}">
                        @foreach ($article->content->faqItems as $item)
                            <details id="{{ $item['id'] }}" class="group rounded-control border border-slate-200 bg-slate-50 open:bg-white" wire:key="faq-{{ $article->publicId }}-{{ $item['id'] }}">
                                <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 rounded-control px-4 py-3 font-black text-slate-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                                    <span>{{ $item['question'] }}</span>
                                    <x-ui.icon name="fa-solid fa-chevron-down shrink-0 text-slate-400 transition-transform group-open:rotate-180 motion-reduce:transition-none" />
                                </summary>
                                <div class="help-article-content border-t border-slate-200 px-4 py-4">{!! $item['answer'] !!}</div>
                            </details>
                        @endforeach
                    </section>
                @else
                    <div class="help-article-content mt-6">{!! $article->content->html !!}</div>
                @endif
            </article>

            @if ($article->feedbackEnabled)
                <section aria-label="{{ __('help.accessibility.feedback_controls') }}" class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-6">
                    <h2 class="text-lg font-black text-slate-900">{{ __('help.feedback.title') }}</h2>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <button type="button" wire:click="submitFeedback('helpful')" wire:loading.attr="disabled" wire:target="submitFeedback" @class(['min-h-11 rounded-control px-4 py-2 text-sm font-black', 'bg-emerald-700 text-white' => $feedbackValue === 'helpful', 'bg-slate-100 text-slate-700 hover:bg-slate-200' => $feedbackValue !== 'helpful'])><x-ui.icon name="fa-regular fa-thumbs-up mr-2" />{{ __('help.feedback.yes') }}</button>
                        <button type="button" wire:click="submitFeedback('not_helpful')" wire:loading.attr="disabled" wire:target="submitFeedback" @class(['min-h-11 rounded-control px-4 py-2 text-sm font-black', 'bg-rose-700 text-white' => $feedbackValue === 'not_helpful', 'bg-slate-100 text-slate-700 hover:bg-slate-200' => $feedbackValue !== 'not_helpful'])><x-ui.icon name="fa-regular fa-thumbs-down mr-2" />{{ __('help.feedback.no') }}</button>
                    </div>
                    @if ($feedbackValue === 'not_helpful')
                        <div class="mt-4 max-w-xl">
                            <label for="help-feedback-reason" class="block text-sm font-bold text-slate-700">{{ __('help.feedback.reason_label') }}</label>
                            <select id="help-feedback-reason" wire:model="feedbackReason" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm">
                                <option value="">{{ __('help.feedback.reason_label') }}</option>
                                @foreach ($feedbackReasons as $reason)
                                    <option value="{{ $reason['value'] }}">{{ $reason['label'] }}</option>
                                @endforeach
                            </select>
                            <button type="button" wire:click="submitFeedback('not_helpful')" wire:loading.attr="disabled" wire:target="submitFeedback" class="mt-2 min-h-11 rounded-control bg-slate-800 px-4 py-2 text-sm font-black text-white disabled:cursor-wait disabled:opacity-60">{{ __('help.feedback.send_reason') }}</button>
                        </div>
                    @endif
                    <p wire:loading.delay wire:target="submitFeedback" role="status" class="mt-3 text-sm text-slate-600">{{ __('help.states.submitting') }}</p>
                </section>
            @endif

            <section class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-6">
                <button type="button" wire:click="$toggle('showReportForm')" class="min-h-11 rounded-control px-2 text-left text-sm font-black text-emerald-700 hover:underline" aria-expanded="{{ $showReportForm ? 'true' : 'false' }}">{{ __('help.reports.open') }}</button>
                @if ($showReportForm)
                    <form wire:submit="submitReport" class="mt-4 max-w-2xl space-y-4" aria-label="{{ __('help.accessibility.report_form') }}">
                        <div>
                            <label for="help-report-reason" class="block text-sm font-bold text-slate-700">{{ __('help.reports.reason_label') }}</label>
                            <select id="help-report-reason" wire:model="reportReason" required class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm">
                                <option value="">{{ __('help.reports.reason_label') }}</option>
                                @foreach ($reportReasons as $reason)
                                    <option value="{{ $reason['value'] }}">{{ $reason['label'] }}</option>
                                @endforeach
                            </select>
                            @error('reportReason') <p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="help-report-details" class="block text-sm font-bold text-slate-700">{{ __('help.reports.details_label') }}</label>
                            <textarea id="help-report-details" wire:model="reportDetails" maxlength="1000" rows="4" placeholder="{{ __('help.reports.details_placeholder') }}" class="mt-2 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm"></textarea>
                            @error('reportDetails') <p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" wire:loading.attr="disabled" wire:target="submitReport" class="min-h-11 rounded-control bg-emerald-700 px-4 py-2 text-sm font-black text-white">{{ __('help.reports.submit') }}</button>
                            <button type="button" wire:click="$set('showReportForm', false)" class="min-h-11 rounded-control bg-slate-100 px-4 py-2 text-sm font-bold text-slate-700">{{ __('help.reports.cancel') }}</button>
                        </div>
                    </form>
                @endif
            </section>

            @if ($statusMessage !== null)
                <p role="status" aria-live="polite" class="rounded-control border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-900">{{ $statusMessage }}</p>
            @endif
            @if ($actionError !== null)
                <p role="alert" class="rounded-control border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-900">{{ $actionError }}</p>
            @endif
        </main>

        <aside class="min-w-0 space-y-4 lg:sticky lg:top-4 lg:h-fit">
            @if ($article->content->tableOfContents !== [] && $article->tableOfContentsEnabled)
                <nav aria-label="{{ __('help.accessibility.article_navigation') }}" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel">
                    <h2 class="font-black text-slate-900">{{ __('help.article.toc') }}</h2>
                    <ol class="mt-3 space-y-1 text-sm">
                        @foreach ($article->content->tableOfContents as $item)
                            <li @class(['pl-3' => $item['level'] === 3])><a href="#{{ $item['id'] }}" class="block min-h-9 rounded-control px-2 py-2 font-semibold text-slate-600 hover:bg-slate-50 hover:text-emerald-700">{{ $item['label'] }}</a></li>
                        @endforeach
                    </ol>
                </nav>
            @endif

            @if ($article->related !== [])
                <section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel">
                    <h2 class="font-black text-slate-900">{{ __('help.article.related') }}</h2>
                    <ul class="mt-3 space-y-3">
                        @foreach ($article->related as $related)
                            <li><a href="{{ $related->url }}" class="block break-words text-sm font-bold leading-5 text-emerald-700 hover:underline">{{ $related->title }}</a><span class="mt-1 block text-xs text-slate-500">{{ $related->categoryTitle }}</span></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($article->escalations !== [])
                <section aria-label="{{ __('help.accessibility.escalations') }}" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel">
                    <h2 class="font-black text-slate-900">{{ __('help.article.still_need_help') }}</h2>
                    <p class="mt-2 text-sm leading-5 text-slate-600">{{ __('help.article.still_need_help_description') }}</p>
                    <div class="mt-3 space-y-2">
                        @foreach ($article->escalations as $escalation)
                            @if ($escalation->url !== null)
                                <a href="{{ $escalation->url }}" class="block min-h-11 rounded-control bg-emerald-700 px-3 py-2.5 text-sm font-black text-white hover:bg-emerald-600">{{ $escalation->label }}</a>
                            @else
                                <div class="rounded-control border border-slate-200 bg-slate-50 p-3"><strong class="block text-sm text-slate-800">{{ $escalation->label }}</strong><p class="mt-1 text-xs leading-5 text-slate-600">{{ $escalation->description }}</p></div>
                            @endif
                        @endforeach
                    </div>
                </section>
            @endif
        </aside>
    </div>
</div>
