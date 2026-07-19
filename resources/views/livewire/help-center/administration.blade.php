<div class="space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-6">
        <h1 class="text-2xl font-black text-slate-900 sm:text-3xl">{{ __('help.admin.title') }}</h1>
        <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-600">{{ __('help.admin.description') }}</p>
    </header>

    @if ($statusMessage !== null)
        <p role="status" class="rounded-control border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-900">{{ $statusMessage }}</p>
    @endif

    <nav aria-label="{{ __('help.admin.title') }}" class="flex flex-wrap gap-2 rounded-panel border border-slate-200 bg-white p-2 shadow-panel">
        @foreach (['articles', 'categories', 'feedback', 'reports'] as $adminTab)
            <button type="button" wire:click="$set('tab', '{{ $adminTab }}')" @class(['min-h-11 rounded-control px-4 py-2 text-sm font-black', 'bg-emerald-700 text-white' => $tab === $adminTab, 'text-slate-700 hover:bg-slate-100' => $tab !== $adminTab])>{{ __('help.admin.'.$adminTab) }}</button>
        @endforeach
    </nav>

    <div wire:loading.delay role="status" aria-live="polite" class="rounded-control bg-sky-50 px-4 py-3 text-sm font-bold text-sky-800">{{ __('help.states.loading') }}</div>

    @island(name: 'help-administration-pagination', always: true, with: $this->paginationIslandPage)
    @if ($tab === 'articles')
        <x-ui.pagination-region name="help-articles-results">
        <div class="grid gap-5 xl:grid-cols-[22rem_minmax(0,1fr)]">
            <aside class="h-fit rounded-panel border border-slate-200 bg-white p-4 shadow-panel xl:sticky xl:top-4">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="font-black text-slate-900">{{ __('help.admin.articles') }}</h2>
                    <button type="button" wire:click="newArticle" class="min-h-11 rounded-control bg-emerald-700 px-3 py-2 text-sm font-black text-white">{{ __('help.admin.create_article') }}</button>
                </div>
                <div class="mt-4 grid gap-3 sm:grid-cols-3 xl:grid-cols-1">
                    <select wire:model.live="statusFilter" aria-label="{{ __('help.admin.fields.status') }}" class="min-h-11 rounded-control border border-slate-300 bg-white px-3 py-2 text-sm">
                        <option value="">{{ __('help.admin.filters.all_statuses') }}</option>
                        @foreach ($statusOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach
                    </select>
                    <select wire:model.live="localeFilter" aria-label="{{ __('help.admin.fields.locale') }}" class="min-h-11 rounded-control border border-slate-300 bg-white px-3 py-2 text-sm">
                        <option value="ru">RU</option><option value="en">EN</option>
                    </select>
                    <select wire:model.live="reviewFilter" aria-label="{{ __('help.admin.filters.due_review') }}" class="min-h-11 rounded-control border border-slate-300 bg-white px-3 py-2 text-sm">
                        <option value="all">{{ __('help.admin.filters.all_statuses') }}</option>
                        <option value="due">{{ __('help.admin.filters.due_review') }}</option>
                        <option value="broken">{{ __('help.admin.filters.broken_links') }}</option>
                    </select>
                </div>
                <ul class="mt-4 max-h-[60vh] space-y-2 overflow-y-auto">
                    @forelse ($articles as $item)
                        <li>
                            <button type="button" wire:click="editArticle({{ $item->id }}, '{{ $localeFilter }}')" @class(['w-full rounded-control border p-3 text-left', 'border-emerald-300 bg-emerald-50' => $articleId === $item->id, 'border-slate-200 hover:bg-slate-50' => $articleId !== $item->id])>
                                <span class="block break-words text-sm font-black text-slate-800">{{ $item->translations->first()?->title ?? $item->code }}</span>
                                <span class="mt-1 flex flex-wrap gap-2 text-xs text-slate-500"><span>{{ $publicationStatusLabels[$item->status->value] }}</span><span>{{ $item->category?->code }}</span><span>{{ __('help.admin.counters.revisions', ['count' => $item->revisions_count]) }}</span><span>{{ __('help.admin.counters.feedback', ['count' => $item->feedback_count]) }}</span><span>{{ __('help.admin.counters.reports', ['count' => $item->open_reports_count]) }}</span></span>
                            </button>
                        </li>
                    @empty
                        <li class="text-sm text-slate-600">{{ __('help.admin.empty') }}</li>
                    @endforelse
                </ul>
                <div class="mt-3">{{ $articles->links(data: ['region' => 'help-articles-results']) }}</div>
            </aside>

            <section class="min-w-0 rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-xl font-black text-slate-900">{{ $articleId > 0 ? __('help.admin.edit_article') : __('help.admin.create_article') }}</h2>
                    @if ($previewUrl !== null)
                        <a href="{{ $previewUrl }}" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center rounded-control bg-slate-100 px-4 py-2 text-sm font-black text-slate-700">{{ __('help.admin.preview') }}</a>
                    @endif
                </div>

                <form wire:submit="saveArticle" data-help-editor data-help-editor-warning="{{ __('help.admin.unsaved_warning') }}" class="mt-5 space-y-5">
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div><label for="help-admin-code" class="text-sm font-bold">{{ __('help.admin.fields.code') }}</label><input id="help-admin-code" wire:model="code" required maxlength="96" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"></div>
                        <div><label for="help-admin-category" class="text-sm font-bold">{{ __('help.admin.fields.category') }}</label><select id="help-admin-category" wire:model="categoryId" required class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2"><option value="">{{ __('help.admin.fields.category') }}</option>@foreach ($categories as $category)<option value="{{ $category->id }}">{{ $category->translations->firstWhere('locale', $locale)?->title ?? $category->code }}</option>@endforeach</select></div>
                        <div><label for="help-admin-locale" class="text-sm font-bold">{{ __('help.admin.fields.locale') }}</label><select id="help-admin-locale" wire:change="switchLocale($event.target.value)" data-help-locale-switch data-help-current-locale="{{ $locale }}" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2"><option value="ru" @selected($locale === 'ru')>RU</option><option value="en" @selected($locale === 'en')>EN</option></select></div>
                        <div><label for="help-admin-type" class="text-sm font-bold">{{ __('help.admin.fields.type') }}</label><select id="help-admin-type" wire:model="type" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2">@foreach ($typeOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div>
                        <div><label for="help-admin-audience" class="text-sm font-bold">{{ __('help.admin.fields.audience') }}</label><select id="help-admin-audience" wire:model="audience" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2">@foreach ($audienceOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div>
                        <div><label for="help-admin-owner" class="text-sm font-bold">{{ __('help.admin.fields.owner') }}</label><select id="help-admin-owner" wire:model="ownerTeam" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2">@foreach ($ownerOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div>
                        <div><label for="help-admin-feature" class="text-sm font-bold">{{ __('help.admin.fields.feature') }}</label><select id="help-admin-feature" wire:model="featureCode" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2">@foreach ($featureOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div>
                        <div><label for="help-admin-primary" class="text-sm font-bold">{{ __('help.admin.fields.primary_escalation') }}</label><select id="help-admin-primary" wire:model="primaryEscalation" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2">@foreach ($escalationOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div>
                        <div><label for="help-admin-secondary" class="text-sm font-bold">{{ __('help.admin.fields.secondary_escalation') }}</label><select id="help-admin-secondary" wire:model="secondaryEscalation" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2">@foreach ($escalationOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div>
                        <div><label for="help-admin-issue" class="text-sm font-bold">{{ __('help.admin.fields.issue_type') }}</label><select id="help-admin-issue" wire:model="issueType" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2"><option value="">—</option>@foreach ($issueTypeOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div>
                        <div><label for="help-admin-request" class="text-sm font-bold">{{ __('help.admin.fields.request_type') }}</label><select id="help-admin-request" wire:model="requestType" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2"><option value="">—</option>@foreach ($requestTypeOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div>
                        <div><label for="help-admin-slug" class="text-sm font-bold">{{ __('help.admin.fields.slug') }}</label><input id="help-admin-slug" wire:model="slug" required maxlength="180" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"></div>
                    </div>

                    <div><label for="help-admin-title" class="text-sm font-bold">{{ __('help.admin.fields.title') }}</label><input id="help-admin-title" wire:model="title" required maxlength="220" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"></div>
                    <div><label for="help-admin-summary" class="text-sm font-bold">{{ __('help.admin.fields.summary') }}</label><textarea id="help-admin-summary" wire:model="summary" required maxlength="700" rows="3" class="mt-2 w-full rounded-control border border-slate-300 px-3 py-2"></textarea></div>
                    <div><label for="help-admin-body" class="text-sm font-bold">{{ __('help.admin.fields.body') }}</label><textarea id="help-admin-body" wire:model="bodyMarkdown" required maxlength="60000" rows="18" class="mt-2 w-full rounded-control border border-slate-300 px-3 py-2 font-mono text-sm"></textarea></div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div><label for="help-admin-keywords" class="text-sm font-bold">{{ __('help.admin.fields.keywords') }}</label><textarea id="help-admin-keywords" wire:model="keywords" maxlength="2000" rows="4" class="mt-2 w-full rounded-control border border-slate-300 px-3 py-2"></textarea></div>
                        <div><label for="help-admin-aliases" class="text-sm font-bold">{{ __('help.admin.fields.aliases') }}</label><textarea id="help-admin-aliases" wire:model="aliases" maxlength="4000" rows="4" class="mt-2 w-full rounded-control border border-slate-300 px-3 py-2"></textarea></div>
                        <div><label for="help-admin-related" class="text-sm font-bold">{{ __('help.article.related') }}</label><textarea id="help-admin-related" wire:model="relatedCodes" rows="3" class="mt-2 w-full rounded-control border border-slate-300 px-3 py-2"></textarea></div>
                        <div><label for="help-admin-context" class="text-sm font-bold">{{ __('help.admin.fields.contextual_links') }}</label><textarea id="help-admin-context" wire:model="contextualCodes" rows="3" class="mt-2 w-full rounded-control border border-slate-300 px-3 py-2"></textarea></div>
                        <div><label for="help-admin-replacement" class="text-sm font-bold">{{ __('help.admin.fields.replacement') }}</label><input id="help-admin-replacement" wire:model="replacementCode" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"></div>
                        <div><label for="help-admin-note" class="text-sm font-bold">{{ __('help.admin.fields.change_note') }}</label><input id="help-admin-note" wire:model="changeNote" maxlength="500" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"></div>
                    </div>
                    <details class="rounded-control border border-slate-200 p-4"><summary class="font-black text-slate-800">{{ __('help.admin.editorial_details') }}</summary><div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div><label for="help-admin-seo-title" class="text-sm font-bold">{{ __('help.admin.fields.seo_title') }}</label><input id="help-admin-seo-title" wire:model="seoTitle" maxlength="180" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"></div>
                        <div><label for="help-admin-seo-description" class="text-sm font-bold">{{ __('help.admin.fields.seo_description') }}</label><input id="help-admin-seo-description" wire:model="seoDescription" maxlength="320" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"></div>
                        <div><label for="help-admin-callout" class="text-sm font-bold">{{ __('help.admin.fields.callout_type') }}</label><select id="help-admin-callout" wire:model="calloutType" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2"><option value="">—</option>@foreach ($calloutOptions as $option)<option value="{{ $option }}">{{ __('help.callouts.'.$option) }}</option>@endforeach</select></div>
                        <div class="md:col-span-2"><label for="help-admin-callout-text" class="text-sm font-bold">{{ __('help.admin.fields.callout_text') }}</label><input id="help-admin-callout-text" wire:model="calloutText" maxlength="500" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"></div>
                        <div><label for="help-admin-position" class="text-sm font-bold">{{ __('help.admin.fields.position') }}</label><input id="help-admin-position" wire:model="position" type="number" min="0" max="65535" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"></div>
                        <div><label for="help-admin-priority" class="text-sm font-bold">{{ __('help.admin.fields.priority') }}</label><input id="help-admin-priority" wire:model="editorialPriority" type="number" min="0" max="100" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"></div>
                        <label class="flex min-h-11 items-center gap-2"><input type="checkbox" wire:model="featured" class="size-5 rounded border-slate-300">{{ __('help.admin.fields.featured') }}</label>
                        <label class="flex min-h-11 items-center gap-2"><input type="checkbox" wire:model="indexable" class="size-5 rounded border-slate-300">{{ __('help.admin.fields.indexable') }}</label>
                    </div></details>
                    @if ($errors->any())<div role="alert" class="rounded-control border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800"><ul class="list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveArticle" class="min-h-11 rounded-control bg-emerald-700 px-5 py-2 text-sm font-black text-white">{{ __('help.admin.save_draft') }}</button>
                </form>

                @if ($selectedArticle !== null)
                    <div class="mt-6 border-t border-slate-200 pt-5">
                        <h3 class="font-black text-slate-900">{{ __('help.admin.fields.status') }}: {{ $publicationStatusLabels[$selectedArticle->status->value] }}</h3>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($transitionOptions as $target)
                                <button type="button" wire:click="transitionArticle('{{ $target['value'] }}')" wire:confirm="{{ __('help.admin.transition_confirm', ['status' => $target['label']]) }}" wire:loading.attr="disabled" wire:target="transitionArticle" class="min-h-11 rounded-control bg-slate-800 px-4 py-2 text-sm font-black text-white">{{ $target['label'] }}</button>
                            @endforeach
                            <button type="button" wire:click="markReviewed" wire:loading.attr="disabled" wire:target="markReviewed" class="min-h-11 rounded-control bg-sky-100 px-4 py-2 text-sm font-black text-sky-900">{{ __('help.admin.mark_reviewed') }}</button>
                            @if ($replacementCode !== '' && ! $selectedArticleIsArchived)
                                <button type="button" wire:click="mergeArticle" wire:confirm="{{ __('help.admin.merge_confirm', ['code' => $replacementCode]) }}" wire:loading.attr="disabled" wire:target="mergeArticle" class="min-h-11 rounded-control bg-rose-100 px-4 py-2 text-sm font-black text-rose-900">{{ __('help.admin.merge_article') }}</button>
                            @endif
                        </div>
                    </div>
                    @if ($selectedTranslation !== null)
                        <div class="mt-6 border-t border-slate-200 pt-5">
                            <h3 class="font-black text-slate-900">{{ __('help.admin.link_check') }}</h3>
                            <p class="mt-2 text-sm text-slate-600">{{ __('help.admin.links.'.$selectedTranslation->link_status) }}</p>
                            @if ($selectedTranslation->link_errors !== null)
                                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-rose-800">
                                    @foreach ($selectedTranslation->link_errors as $linkError)
                                        <li class="break-words">{{ $linkError }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif
                    <div class="mt-6 border-t border-slate-200 pt-5">
                        <h3 class="font-black text-slate-900">{{ __('help.admin.revisions.title') }}</h3>
                        <ul class="mt-3 space-y-2">@forelse ($revisions as $revision)<li class="flex flex-wrap items-center justify-between gap-2 rounded-control border border-slate-200 p-3"><span class="text-sm"><strong>#{{ $revision->revision }}</strong> · {{ $publicationStatusLabels[$revision->article_status->value] }} · {{ $revisionDates[$revision->id] }}</span><button type="button" wire:click="restoreRevision({{ $revision->id }})" wire:confirm="{{ __('help.admin.restore_confirm', ['revision' => $revision->revision]) }}" wire:loading.attr="disabled" wire:target="restoreRevision" class="min-h-11 rounded-control bg-slate-100 px-3 py-2 text-sm font-bold">{{ __('help.admin.restore_revision') }}</button></li>@empty<li class="text-sm text-slate-600">{{ __('help.admin.empty') }}</li>@endforelse</ul>
                    </div>
                @endif
            </section>
        </div>
        </x-ui.pagination-region>
    @elseif ($tab === 'categories')
        <div class="grid gap-5 lg:grid-cols-[22rem_minmax(0,1fr)]">
            <section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel"><div class="flex items-center justify-between gap-2"><h2 class="font-black">{{ __('help.admin.categories') }}</h2><button type="button" wire:click="newCategory" aria-label="{{ __('help.admin.create_category') }}" class="min-h-11 rounded-control bg-emerald-700 px-3 py-2 text-sm font-black text-white">+</button></div><ul class="mt-4 space-y-2">@foreach ($categories as $category)<li><button type="button" wire:click="editCategory({{ $category->id }})" class="w-full rounded-control border border-slate-200 p-3 text-left text-sm"><strong>{{ $category->translations->firstWhere('locale', 'ru')?->title ?? $category->code }}</strong><span class="block text-xs text-slate-500">{{ $category->code }} · {{ $category->articles_count }}</span></button></li>@endforeach</ul></section>
            <form wire:submit="saveCategory" class="space-y-4 rounded-panel border border-slate-200 bg-white p-5 shadow-panel"><h2 class="text-xl font-black">{{ __('help.admin.categories') }}</h2><div class="grid gap-4 md:grid-cols-2"><div><label class="text-sm font-bold">{{ __('help.admin.fields.code') }}</label><input wire:model="categoryCode" required class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3"></div><div><label class="text-sm font-bold">{{ __('help.admin.fields.position') }}</label><input wire:model="categoryPosition" type="number" min="0" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3"></div><div><label class="text-sm font-bold">{{ __('help.admin.fields.parent') }}</label><select wire:model="categoryParentId" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3"><option value="">—</option>@foreach ($categories->whereNull('parent_id') as $category)<option value="{{ $category->id }}">{{ $category->code }}</option>@endforeach</select></div><label class="flex min-h-11 items-center gap-2"><input wire:model="categoryVisible" type="checkbox" class="size-5">{{ __('help.admin.fields.visible') }}</label></div>@foreach (['Ru' => 'ru', 'En' => 'en'] as $suffix => $language)<fieldset class="rounded-control border border-slate-200 p-4"><legend class="px-2 font-black">{{ strtoupper($language) }}</legend><div class="grid gap-4 md:grid-cols-2"><div><label class="text-sm font-bold">{{ __('help.admin.fields.slug') }}</label><input wire:model="category{{ $suffix }}Slug" required class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3"></div><div><label class="text-sm font-bold">{{ __('help.admin.fields.title') }}</label><input wire:model="category{{ $suffix }}Title" required class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3"></div><div class="md:col-span-2"><label class="text-sm font-bold">{{ __('help.admin.fields.summary') }}</label><textarea wire:model="category{{ $suffix }}Description" required rows="3" class="mt-2 w-full rounded-control border border-slate-300 px-3 py-2"></textarea></div></div></fieldset>@endforeach<button type="submit" class="min-h-11 rounded-control bg-emerald-700 px-5 py-2 text-sm font-black text-white">{{ __('help.admin.save_draft') }}</button></form>
        </div>
    @elseif ($tab === 'feedback')
        <x-ui.pagination-region name="help-feedback-results">
        <section class="overflow-hidden rounded-panel border border-slate-200 bg-white p-5 shadow-panel"><h2 class="text-xl font-black">{{ __('help.admin.feedback') }}</h2><table class="mt-4 w-full table-fixed text-left text-sm"><thead><tr class="border-b border-slate-200"><th class="p-3">{{ __('help.admin.fields.article') }}</th><th class="p-3">{{ __('help.admin.fields.locale') }}</th><th class="p-3">{{ __('help.admin.fields.value') }}</th><th class="p-3">{{ __('help.admin.fields.count') }}</th></tr></thead><tbody>@forelse ($feedbackAggregates as $aggregate)<tr class="border-b border-slate-100"><td class="break-words p-3 font-bold">{{ $aggregate->translation?->title }}</td><td class="break-words p-3">{{ $aggregate->translation?->locale }}</td><td class="break-words p-3">{{ $feedbackValueLabels[$aggregate->value->value] }}</td><td class="break-words p-3">{{ $aggregate->responses_count }}</td></tr>@empty<tr><td colspan="4" class="p-5 text-slate-600">{{ __('help.states.no_feedback') }}</td></tr>@endforelse</tbody></table><div class="mt-3">{{ $feedbackAggregates->links(data: ['region' => 'help-feedback-results']) }}</div></section>
        </x-ui.pagination-region>
    @else
        <x-ui.pagination-region name="help-reports-results">
        <section class="space-y-4"><h2 class="text-xl font-black text-slate-900">{{ __('help.admin.reports') }}</h2>@forelse ($reports as $report)<article class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel"><div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="font-black text-slate-900">{{ $report->translation?->title ?? $report->article?->code }}</h3><p class="mt-1 text-xs text-slate-500">{{ $report->locale }} · {{ $reportReasonLabels[$report->reason->value] }} · {{ $reportDates[$report->id] }}</p></div><span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-black text-amber-900">{{ __('help.admin.report_statuses.'.$report->status) }}</span></div>@if ($report->details)<p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $report->details }}</p>@endif<div class="mt-4 flex flex-wrap gap-2"><input wire:model="reportPrivateNotes.{{ $report->id }}" aria-label="{{ __('help.admin.fields.private_note') }}" maxlength="2000" placeholder="{{ __('help.admin.fields.private_note') }}" class="min-h-11 min-w-0 flex-1 rounded-control border border-slate-300 px-3"><button type="button" wire:click="reviewReport({{ $report->id }}, 'reviewed')" wire:loading.attr="disabled" wire:target="reviewReport" class="min-h-11 rounded-control bg-emerald-700 px-4 py-2 text-sm font-black text-white">{{ __('help.admin.reviewed') }}</button><button type="button" wire:click="reviewReport({{ $report->id }}, 'dismissed')" wire:loading.attr="disabled" wire:target="reviewReport" class="min-h-11 rounded-control bg-slate-100 px-4 py-2 text-sm font-black text-slate-700">{{ __('help.admin.dismiss') }}</button></div></article>@empty<div class="rounded-panel border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600">{{ __('help.admin.empty') }}</div>@endforelse<div>{{ $reports->links(data: ['region' => 'help-reports-results']) }}</div></section>
        </x-ui.pagination-region>
    @endif
    @endisland
</div>
