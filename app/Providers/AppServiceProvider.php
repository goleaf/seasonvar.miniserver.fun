<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Collections\CatalogCollectionSchema;
use App\Services\Comments\CommentSchema;
use App\Services\Reviews\ReviewSchema;
use App\Services\Tags\TagSchema;
use App\Support\Cache\CacheEventReporter;
use App\View\ViewData\AppLayoutData;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        config()->set('livewire.temporary_file_upload.middleware', 'web');
        $this->app->scopedIf(CatalogCollectionSchema::class);
        $this->app->scopedIf(CommentSchema::class);
        $this->app->scopedIf(ReviewSchema::class);
        $this->app->scopedIf(TagSchema::class);
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
        Gate::define('manage-comments', $catalogAdministrator);
        Gate::define('manage-reviews', $catalogAdministrator);

        Livewire::setUpdateRoute(function ($handle, string $path) {
            return Route::post($path, $handle)
                ->middleware('web');
        });
        Livewire::addPersistentMiddleware(AuthenticateSession::class);

        Model::shouldBeStrict(! $this->app->isProduction());
        DB::prohibitDestructiveCommands($this->app->isProduction());

        ViewFacade::composer('layouts.app', function (View $view): void {
            $view->with(app(AppLayoutData::class)->from($view->getData()));
        });
    }
}
