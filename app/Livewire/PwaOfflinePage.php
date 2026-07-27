<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class PwaOfflinePage extends Component
{
    public function render(): View
    {
        $locale = app()->getLocale();

        return view('livewire.pwa-offline-page')
            ->extends('layouts.offline', [
                'htmlLocale' => str_replace('_', '-', $locale),
                'pwaHelpSnapshotUrl' => route('pwa.help-snapshot', ['locale' => $locale], absolute: false),
                'title' => __('pwa.offline.title'),
            ])
            ->section('content');
    }
}
