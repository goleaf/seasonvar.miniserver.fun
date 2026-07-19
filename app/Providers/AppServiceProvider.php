<?php

namespace App\Providers;

use App\Http\Middleware\EnsureAccountAccess;
use App\Http\Middleware\EnsureAdministrator;
use App\Models\CatalogCollection;
use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleUpdateState;
use App\Models\CatalogTitleUserState;
use App\Models\Comment;
use App\Models\Episode;
use App\Models\EpisodePlaybackMarker;
use App\Models\EpisodeViewProgress;
use App\Models\HelpArticle;
use App\Models\LicensedMedia;
use App\Models\TechnicalIssue;
use App\Models\UserAccountSetting;
use App\Models\UserProfile;
use App\Models\UserTag;
use App\Observers\EpisodeReleaseScheduleObserver;
use App\Observers\LicensedMediaReleaseScheduleObserver;
use App\Observers\UserPortalCacheObserver;
use App\Policies\AccountSettingsPolicy;
use App\Policies\HelpArticlePolicy;
use App\Policies\TechnicalIssuePolicy;
use App\Services\Admin\AdminAccessRegistry;
use App\Services\Admin\AdminAccessResolver;
use App\Services\Admin\AdminGateRegistrar;
use App\Services\Auth\AccountAccessResolver;
use App\Services\Auth\AccountSettingsSchema;
use App\Services\Catalog\PersonalLibrarySchema;
use App\Services\Collections\CatalogCollectionSchema;
use App\Services\Comments\CommentSchema;
use App\Services\ContentRequests\ContentRequestSchema;
use App\Services\HelpCenter\HelpCenterSchema;
use App\Services\Premium\PremiumAccessResolver;
use App\Services\Premium\PremiumPaymentGatewayRegistry;
use App\Services\Premium\PremiumSchema;
use App\Services\ReleaseCalendar\ReleaseCalendarSchema;
use App\Services\Reviews\ReviewSchema;
use App\Services\Tags\TagSchema;
use App\Services\TechnicalIssues\TechnicalIssueSchema;
use App\Services\UserPortal\UserPortalCacheInvalidator;
use App\Support\Cache\CacheEventReporter;
use App\View\ViewData\AppLayoutData;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scopedIf(CatalogCollectionSchema::class);
        $this->app->singletonIf(AdminAccessRegistry::class);
        $this->app->scopedIf(AdminAccessResolver::class);
        $this->app->scopedIf(AdminGateRegistrar::class);
        $this->app->scopedIf(AccountAccessResolver::class);
        $this->app->scopedIf(AccountSettingsSchema::class);
        $this->app->scopedIf(CommentSchema::class);
        $this->app->scopedIf(ContentRequestSchema::class);
        $this->app->scopedIf(ReviewSchema::class);
        $this->app->scopedIf(ReleaseCalendarSchema::class);
        $this->app->scopedIf(HelpCenterSchema::class);
        $this->app->scopedIf(PremiumSchema::class);
        $this->app->scopedIf(PersonalLibrarySchema::class);
        $this->app->scopedIf(PremiumAccessResolver::class);
        $this->app->singleton(PremiumPaymentGatewayRegistry::class, static fn (): PremiumPaymentGatewayRegistry => new PremiumPaymentGatewayRegistry);
        $this->app->scopedIf(TagSchema::class);
        $this->app->scopedIf(UserPortalCacheInvalidator::class);
        $this->app->scopedIf(TechnicalIssueSchema::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(static fn (): Password => Password::min(12)
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols());

        RateLimiter::for('media-downloads', function (Request $request): array {
            $userId = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');
            $media = $request->route('licensedMedia');
            $mediaId = $media instanceof LicensedMedia ? (string) $media->id : (string) $media;
            $response = static fn (Request $request, array $headers) => response(
                __('catalog.download.rate_limited'),
                429,
                [
                    ...$headers,
                    'Cache-Control' => 'private, no-store, max-age=0',
                    'X-Content-Type-Options' => 'nosniff',
                ],
            );

            return [
                Limit::perMinute(max(1, (int) config('playback.downloads.requests_per_minute', 12)))
                    ->by($userId.'|'.$request->ip())
                    ->response($response),
                Limit::perMinute(max(1, (int) config('playback.downloads.media_requests_per_minute', 4)))
                    ->by($userId.'|'.$mediaId)
                    ->response($response),
            ];
        });

        RateLimiter::for('livewire-uploads', function (Request $request): Limit {
            $identity = $request->user()?->getAuthIdentifier();
            $key = $identity !== null
                ? 'user:'.$identity
                : 'guest:'.hash('sha256', (string) $request->ip());

            return Limit::perMinute(max(1, (int) config('technical-issues.rate_limits.upload_per_minute', 30)))
                ->by($key)
                ->response(static fn (Request $request, array $headers) => response(
                    __('issues.errors.rate_limited'),
                    429,
                    [...$headers, 'Cache-Control' => 'private, no-store, max-age=0'],
                ));
        });

        RateLimiter::for('premium-webhooks', fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('premium.rate_limits.webhook_per_minute', 120)),
        )->by(hash('sha256', (string) $request->ip())));

        RateLimiter::for('administration', function (Request $request): Limit {
            $identity = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');

            return Limit::perMinute(120)
                ->by('admin:'.$identity.'|'.hash('sha256', (string) $request->ip()));
        });

        Event::listen(CacheHit::class, fn (CacheHit $event) => app(CacheEventReporter::class)->record($event, 'hit'));
        Event::listen(CacheMissed::class, fn (CacheMissed $event) => app(CacheEventReporter::class)->record($event, 'miss'));
        Event::listen(KeyWritten::class, fn (KeyWritten $event) => app(CacheEventReporter::class)->record($event, 'write'));
        Event::listen(KeyForgotten::class, fn (KeyForgotten $event) => app(CacheEventReporter::class)->record($event, 'forget'));
        Event::listen(CacheFailedOver::class, fn (CacheFailedOver $event) => app(CacheEventReporter::class)->failedOver($event));

        app(AdminGateRegistrar::class)->register();
        Gate::policy(TechnicalIssue::class, TechnicalIssuePolicy::class);
        Gate::policy(HelpArticle::class, HelpArticlePolicy::class);
        Gate::define('view-account-settings', [AccountSettingsPolicy::class, 'view']);
        Gate::define('update-account-settings', [AccountSettingsPolicy::class, 'update']);

        Livewire::setUpdateRoute(function ($handle, string $path) {
            return Route::post($path, $handle)
                ->middleware('web');
        });
        Livewire::addPersistentMiddleware(AuthenticateSession::class);
        Livewire::addPersistentMiddleware(EnsureAccountAccess::class);
        Livewire::addPersistentMiddleware(EnsureAdministrator::class);

        Episode::observe(EpisodeReleaseScheduleObserver::class);
        LicensedMedia::observe(LicensedMediaReleaseScheduleObserver::class);

        foreach ([
            CatalogCollection::class,
            CatalogTitleReview::class,
            CatalogTitleUpdateState::class,
            CatalogTitleUserState::class,
            Comment::class,
            EpisodePlaybackMarker::class,
            EpisodeViewProgress::class,
            UserAccountSetting::class,
            UserProfile::class,
            UserTag::class,
        ] as $ownerModel) {
            $ownerModel::observe(UserPortalCacheObserver::class);
        }

        Model::shouldBeStrict(! $this->app->isProduction());
        DB::prohibitDestructiveCommands($this->app->isProduction());

        ViewFacade::composer('layouts.app', function (View $view): void {
            $view->with(app(AppLayoutData::class)->from($view->getData()));
        });
    }
}
