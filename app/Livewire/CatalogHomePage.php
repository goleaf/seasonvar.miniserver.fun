<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Services\Catalog\CatalogHomePageBuilder;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class CatalogHomePage extends Component
{
    public function render(CatalogHomePageBuilder $page): View
    {
        $viewer = auth()->user();
        $data = $page->data($viewer instanceof User ? $viewer : null);

        return view('livewire.catalog-home-page', $data)
            ->extends('layouts.app', [
                'title' => $data['seo']['title'] ?? __('home.title'),
                'seo' => $data['seo'] ?? [],
            ])
            ->section('content');
    }
}
