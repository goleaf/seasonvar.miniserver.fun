<?php

declare(strict_types=1);

namespace App\Livewire\Administration;

use App\Actions\Administration\InvalidateAdministeredCache;
use App\Actions\Administration\ReindexCatalogResource;
use App\Enums\AdminPermission;
use App\Models\User;
use App\Services\Admin\AdminAccessResolver;
use App\Services\Admin\AdminOperationsQuery;
use App\Support\Cache\CacheDomain;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

final class AdminOperationsPage extends Component
{
    public string $cacheDomain = '';

    public string $catalogSlug = '';

    public string $cacheOperationKey = '';

    public string $searchOperationKey = '';

    public string $statusMessage = '';

    public function mount(): void
    {
        Gate::authorize(AdminPermission::OperationsView->value);
        $this->renewOperationKeys();
    }

    public function invalidateCache(InvalidateAdministeredCache $action): void
    {
        $validated = $this->validate([
            'cacheDomain' => ['required', Rule::in(collect(InvalidateAdministeredCache::domains())->pluck('value')->all())],
            'cacheOperationKey' => ['required', 'uuid'],
        ]);
        $action->handle($this->user(), $validated['cacheDomain'], true, $validated['cacheOperationKey']);
        $this->statusMessage = __('administration.operations.cache_completed');
        $this->cacheDomain = '';
        $this->cacheOperationKey = (string) Str::uuid();
    }

    public function reindexResource(ReindexCatalogResource $action): void
    {
        $validated = $this->validate([
            'catalogSlug' => ['required', 'string', 'max:255', 'not_regex:/\//'],
            'searchOperationKey' => ['required', 'uuid'],
        ]);
        $action->handle($this->user(), $validated['catalogSlug'], true, $validated['searchOperationKey']);
        $this->statusMessage = __('administration.operations.reindex_completed');
        $this->catalogSlug = '';
        $this->searchOperationKey = (string) Str::uuid();
    }

    public function render(AdminOperationsQuery $query, AdminAccessResolver $access): View
    {
        Gate::authorize(AdminPermission::OperationsView->value);
        $user = $this->user();
        $summary = $query->summary();

        return view('livewire.administration.operations', [
            ...$summary,
            'canInvalidateCache' => $access->allows($user, AdminPermission::CacheInvalidate),
            'canReindex' => $access->allows($user, AdminPermission::SearchReindex),
            'cacheDomains' => collect(InvalidateAdministeredCache::domains())->mapWithKeys(
                fn (CacheDomain $domain): array => [$domain->value => __('administration.operations.cache_domains.'.str_replace('-', '_', $domain->value))],
            )->all(),
        ])->extends('layouts.app', [
            'title' => __('administration.operations.title'),
            'seo' => [
                'title' => __('administration.operations.title'),
                'description' => __('administration.operations.description'),
                'robots' => 'noindex,nofollow',
                'canonical' => route('admin.operations'),
                'alternates' => [],
                'social' => false,
                'jsonLd' => [],
            ],
        ])->section('content');
    }

    private function renewOperationKeys(): void
    {
        $this->cacheOperationKey = (string) Str::uuid();
        $this->searchOperationKey = (string) Str::uuid();
    }

    private function user(): User
    {
        $user = request()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
