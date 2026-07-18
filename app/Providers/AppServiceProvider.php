<?php

namespace App\Providers;

use App\Models\Episode;
use App\Models\HelpArticle;
use App\Models\LicensedMedia;
use App\Models\TechnicalIssue;
use App\Models\User;
use App\Observers\EpisodeReleaseScheduleObserver;
use App\Observers\LicensedMediaReleaseScheduleObserver;
use App\Policies\AccountSettingsPolicy;
use App\Policies\HelpArticlePolicy;
use App\Policies\TechnicalIssuePolicy;
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
use Illuminate\Support\Str;
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
        Gate::define('manage-content-requests', $catalogAdministrator);
        Gate::define('manage-technical-issues', $catalogAdministrator);
        Gate::define('manage-release-calendar', $catalogAdministrator);
        Gate::define('manage-help-center', $catalogAdministrator);
        Gate::define('view-premium-administration', $catalogAdministrator);
        $premiumAdministrator = static fn (string $capability): callable => static function (User $user) use ($catalogAdministrator, $capability): bool {
            return $catalogAdministrator($user) && in_array(
                Str::lower($user->email),
                (array) config("premium.administration.{$capability}_emails", []),
                true,
            );
        };
        Gate::define('manage-premium-grants', $premiumAdministrator('grant'));
        Gate::define('manage-premium-promotions', $premiumAdministrator('promotion'));
        Gate::define('view-premium-billing-audit', $premiumAdministrator('billing_audit'));
        Gate::define('reconcile-premium', $premiumAdministrator('reconciliation'));
        Gate::policy(TechnicalIssue::class, TechnicalIssuePolicy::class);
        Gate::policy(HelpArticle::class, HelpArticlePolicy::class);
        Gate::define('view-account-settings', [AccountSettingsPolicy::class, 'view']);
        Gate::define('update-account-settings', [AccountSettingsPolicy::class, 'update']);

        Livewire::setUpdateRoute(function ($handle, string $path) {
            return Route::post($path, $handle)
                ->middleware('web');
        });
        Livewire::addPersistentMiddleware(AuthenticateSession::class);

        Episode::observe(EpisodeReleaseScheduleObserver::class);
        LicensedMedia::observe(LicensedMediaReleaseScheduleObserver::class);

        Model::shouldBeStrict(! $this->app->isProduction());
        DB::prohibitDestructiveCommands($this->app->isProduction());

        ViewFacade::composer('layouts.app', function (View $view): void {
            $view->with(app(AppLayoutData::class)->from($view->getData()));
        });
    }
}
