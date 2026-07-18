@extends('layouts.app', [
    'title' => $title ?? __('auth.errors.forbidden_title'),
    'seo' => [
        'title' => $title ?? __('auth.errors.forbidden_title'),
        'description' => $message ?? __('auth.errors.forbidden_description'),
        'robots' => 'noindex, nofollow',
        'canonical' => route('home'),
    ],
])

@section('content')
    <div class="mx-auto max-w-2xl">
        <section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6" aria-labelledby="forbidden-title">
            <div class="flex items-start gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-rose-50 text-rose-700">
                    <x-ui.icon name="fa-solid fa-link-slash" />
                </span>
                <div class="min-w-0">
                    <h1 id="forbidden-title" class="break-words text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">{{ $title ?? __('auth.errors.forbidden_title') }}</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ $message ?? __('auth.errors.forbidden_description') }}</p>
                </div>
            </div>

            <a href="{{ route('home') }}" class="mt-6 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 sm:w-auto">
                <x-ui.icon name="fa-solid fa-house" />
                <span>{{ __('auth.errors.home') }}</span>
            </a>
        </section>
    </div>
@endsection
