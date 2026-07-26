<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class PwaOfflinePage extends Component
{
    public function render(): View
    {
        return view('livewire.pwa-offline-page')
            ->extends('layouts.offline', [
                'title' => __('pwa.offline.title'),
            ])
            ->section('content');
    }
}
