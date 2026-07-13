<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Cache\CacheEventReporter;
use App\Support\RateLimiting\RequestRateLimitKey;
use App\View\ViewData\AppLayoutData;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
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
        Event::listen(CacheHit::class, fn (CacheHit $event) => app(CacheEventReporter::class)->record($event, 'hit'));
        Event::listen(CacheMissed::class, fn (CacheMissed $event) => app(CacheEventReporter::class)->record($event, 'miss'));
        Event::listen(KeyWritten::class, fn (KeyWritten $event) => app(CacheEventReporter::class)->record($event, 'write'));
        Event::listen(KeyForgotten::class, fn (KeyForgotten $event) => app(CacheEventReporter::class)->record($event, 'forget'));
        Event::listen(CacheFailedOver::class, fn (CacheFailedOver $event) => app(CacheEventReporter::class)->failedOver($event));

        $catalogAdministrator = function (User $user): bool {
            return in_array(
                Str::lower($user->email),
                config('seasonvar.admin_emails', []),
                true,
            );
        };

        Gate::define('manage-seasonvar-imports', $catalogAdministrator);
        Gate::define('manage-catalog', $catalogAdministrator);

        RateLimiter::for('catalog-stats', fn (Request $request): Limit => Limit::perMinute(180)
            ->by(app(RequestRateLimitKey::class)->actor($request)));
        RateLimiter::for('livewire-action', function (Request $request): array {
            $keys = app(RequestRateLimitKey::class);
            $actor = $keys->actor($request);

            return [
                Limit::perMinute(600)->by($actor.':transport'),
                Limit::perMinute(180)->by($actor.':'.$keys->livewireFeature($request)),
            ];
        });

        Livewire::setUpdateRoute(function ($handle, string $path) {
            return Route::post($path, $handle)
                ->middleware(['web', 'throttle:livewire-action']);
        });

        RateLimiter::for('catalog-api', fn (Request $request): Limit => Limit::perMinute(60)
            ->by(app(RequestRateLimitKey::class)->actor($request)));
        RateLimiter::for('infrastructure-health', fn (Request $request): Limit => Limit::perMinute(30)
            ->by(app(RequestRateLimitKey::class)->actor($request)));
        RateLimiter::for('playback-source', fn (Request $request): Limit => Limit::perMinute(120)
            ->by(app(RequestRateLimitKey::class)->actor($request)));

        Model::shouldBeStrict(! $this->app->isProduction());
        DB::prohibitDestructiveCommands($this->app->isProduction());

        ViewFacade::composer('layouts.app', function (View $view): void {
            $view->with(app(AppLayoutData::class)->from($view->getData()));
        });
    }
}
