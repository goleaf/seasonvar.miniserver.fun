<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\AdminPermission;
use App\Models\User;
use App\Services\Admin\AdminAccessResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

final class CatalogAdministrationPage extends Component
{
    #[Url(history: true, except: 'catalog')]
    public string $section = 'catalog';

    public function mount(): void
    {
        Gate::authorize(AdminPermission::ContentView->value);
        $this->normalizeSection();
    }

    public function updatedSection(): void
    {
        $this->normalizeSection();
    }

    public function setSection(string $section): void
    {
        $this->section = $section;
        $this->normalizeSection();
    }

    public function render(AdminAccessResolver $access): View
    {
        $user = request()->user();
        abort_unless($user instanceof User, 401);

        return view('livewire.catalog-administration-page', [
            'canModerateCollections' => $access->allows($user, AdminPermission::CollectionsModerate),
            'canImport' => $access->allows($user, AdminPermission::ImportsExecute),
        ])->extends('layouts.app', [
            'title' => __('collections.admin.catalog_and_collections'),
            'seo' => [
                'title' => __('collections.admin.catalog_and_collections'), 'description' => __('collections.admin.catalog_and_collections_description'),
                'robots' => 'noindex,nofollow', 'canonical' => route('admin.catalog'), 'alternates' => [],
            ],
        ])->section('content');
    }

    private function normalizeSection(): void
    {
        $allowed = Gate::allows(AdminPermission::CollectionsModerate->value)
            ? ['catalog', 'collections']
            : ['catalog'];
        $this->section = in_array($this->section, $allowed, true) ? $this->section : 'catalog';
    }
}
