<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use App\Models\ContentRequest;

final class ContentRequestSeoPresenter
{
    /** @return array<string, mixed> */
    public function directory(bool $filtered, ?string $locale = null): array
    {
        $locales = (array) config('content-requests.supported_locales', ['ru']);

        return [
            'title' => __('requests.directory.seo_title'),
            'description' => __('requests.directory.seo_description'),
            'canonical' => $locale !== null ? route('localized.requests.index', ['locale' => $locale]) : route('requests.index'),
            'robots' => $filtered ? 'noindex, follow' : 'index, follow',
            'alternates' => collect($locales)->mapWithKeys(fn (string $value): array => [$value => route('localized.requests.index', ['locale' => $value])])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(ContentRequest $request, ?string $locale = null): array
    {
        $indexable = $request->is_public && ! in_array($request->status->value, ['rejected', 'duplicate', 'merged', 'withdrawn', 'cancelled'], true);

        $locales = (array) config('content-requests.supported_locales', ['ru']);

        return [
            'title' => __('requests.detail.seo_title', ['title' => $request->title]),
            'description' => __('requests.detail.seo_description', ['type' => $request->type->label(), 'status' => $request->status->label()]),
            'canonical' => $locale !== null
                ? route('localized.requests.show', ['locale' => $locale, 'contentRequest' => $request])
                : route('requests.show', $request),
            'robots' => $indexable ? 'index, follow' : 'noindex, follow',
            'alternates' => collect($locales)->mapWithKeys(fn (string $value): array => [$value => route('localized.requests.show', ['locale' => $value, 'contentRequest' => $request])])->all(),
        ];
    }
}
