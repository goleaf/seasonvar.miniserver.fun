<?php

declare(strict_types=1);

namespace App\Livewire\Administration;

use App\Enums\AdminPermission;
use App\Services\Admin\AdministrationDashboardQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

final class AdministrationDashboardPage extends Component
{
    public function mount(): void
    {
        Gate::authorize(AdminPermission::DashboardView->value);
    }

    public function render(AdministrationDashboardQuery $dashboard): View
    {
        Gate::authorize(AdminPermission::DashboardView->value);

        $user = request()->user();
        abort_unless($user !== null, 401);

        return view('livewire.administration.dashboard', [
            'sections' => $dashboard->for($user),
        ])
            ->extends('layouts.app', [
                'title' => __('administration.dashboard.title'),
                'seo' => [
                    'title' => __('administration.dashboard.title'),
                    'description' => __('administration.dashboard.description'),
                    'robots' => 'noindex,nofollow',
                    'canonical' => route('admin.index'),
                    'alternates' => [],
                    'social' => false,
                    'jsonLd' => [],
                ],
            ])
            ->section('content');
    }
}
