<?php

namespace App\Providers;

use App\View\ViewData\AppLayoutData;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('catalog-stats', function (Request $request): Limit {
            $userId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(180)->by(
                $userId === null ? 'ip:'.$request->ip() : 'user:'.$userId,
            );
        });

        Livewire::setUpdateRoute(function ($handle, string $path) {
            return Route::post($path, $handle)
                ->middleware(['web', 'throttle:catalog-stats']);
        });

        RateLimiter::for('catalog-api', fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip()));

        Model::shouldBeStrict(! $this->app->isProduction());
        DB::prohibitDestructiveCommands($this->app->isProduction());

        ViewFacade::composer('layouts.app', function (View $view): void {
            $view->with(app(AppLayoutData::class)->from($view->getData()));
        });
    }
}
