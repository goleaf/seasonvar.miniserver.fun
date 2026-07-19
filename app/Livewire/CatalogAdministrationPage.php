<?php

declare(strict_types=1);

namespace App\Livewire;

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
        Gate::authorize('manage-catalog');
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

    public function render(): View
    {
        return view('livewire.catalog-administration-page')->extends('layouts.app', [
            'title' => 'Управление каталогом',
            'seo' => [
                'title' => 'Управление каталогом', 'description' => 'Служебное управление каталогом и подборками.',
                'robots' => 'noindex,nofollow', 'canonical' => route('admin.catalog'), 'alternates' => [],
            ],
        ])->section('content');
    }

    private function normalizeSection(): void
    {
        $this->section = in_array($this->section, ['catalog', 'collections'], true) ? $this->section : 'catalog';
    }
}
