<?php

declare(strict_types=1);

namespace App\Livewire\Premium;

use App\Actions\Premium\CreatePremiumCheckout;
use App\Enums\HelpFeature;
use App\Models\User;
use App\Services\Auth\AccountDateTimeFormatter;
use App\Services\Auth\AccountSettingsService;
use App\Services\HelpCenter\HelpContextualLinkService;
use App\Services\Premium\PremiumAccessResolver;
use App\Services\Premium\PremiumPlanQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

final class PremiumPricingPage extends Component
{
    #[Locked]
    public ?string $locale = null;

    #[Locked]
    public string $checkoutToken = '';

    #[Url(as: 'plan', history: true, except: '')]
    public string $selectedPlan = '';

    public function mount(?string $locale = null): void
    {
        $supported = (array) config('catalog-collections.supported_locales', []);
        abort_if($locale !== null && ! in_array($locale, $supported, true), 404);
        $this->locale = $locale;
        $this->checkoutToken = (string) Str::uuid();
        $this->selectedPlan = preg_match('/\A[a-z0-9][a-z0-9_-]{1,63}\z/', $this->selectedPlan) === 1
            ? $this->selectedPlan
            : '';
    }

    public function startCheckout(CreatePremiumCheckout $checkout, PremiumPlanQuery $plans): void
    {
        $plan = $plans->purchasable($this->selectedPlan);

        if ($plan === null) {
            throw ValidationException::withMessages(['selectedPlan' => [__('premium.errors.plan_unavailable')]]);
        }

        $user = Auth::user();

        if (! $user instanceof User) {
            session()->put('url.intended', $this->pricingUrl($plan->code));
            $this->redirectRoute($this->locale !== null ? 'localized.login' : 'login', array_filter([
                'locale' => $this->locale,
            ]));

            return;
        }

        if (! RateLimiter::attempt(
            'premium-checkout:user:'.$user->id,
            max(1, (int) config('premium.rate_limits.checkout_per_minute', 4)),
            static fn (): bool => true,
            60,
        )) {
            throw ValidationException::withMessages(['selectedPlan' => [__('premium.errors.checkout_failed')]]);
        }

        try {
            $created = $checkout->handle(
                $user,
                $plan->code,
                $this->locale ?? app()->getLocale(),
                $this->checkoutToken,
            );
        } catch (ValidationException $exception) {
            $this->checkoutToken = (string) Str::uuid();

            throw $exception;
        }
        $this->checkoutToken = (string) Str::uuid();
        $this->redirect($created->redirectUrl);
    }

    public function render(
        PremiumPlanQuery $plans,
        PremiumAccessResolver $access,
        HelpContextualLinkService $helpLinks,
        AccountSettingsService $settings,
        AccountDateTimeFormatter $dateTimes,
    ): View {
        $locale = $this->locale ?? app()->getLocale();
        $publicPlans = $plans->publicPlans($locale);
        $user = Auth::user();
        $summary = $access->resolve($user);
        $canonical = $this->pricingUrl();
        $accountSettings = $user instanceof User ? $settings->resolve($user) : null;
        $summaryMessage = $summary->active
            ? ($summary->lifetime
                ? __('premium.settings.lifetime')
                : __('premium.settings.active_until', [
                    'date' => $summary->expiresAt !== null && $accountSettings !== null
                        ? $dateTimes->value($summary->expiresAt, $accountSettings->locale, $accountSettings->timezone)
                        : '—',
                ]))
            : null;

        return view('livewire.premium.pricing-page', [
            'plans' => $publicPlans,
            'summary' => $summary,
            'summaryMessage' => $summaryMessage,
            'isAuthenticated' => Auth::check(),
            'settingsUrl' => Auth::check() ? route('settings.index', ['section' => 'premium']) : null,
            'premiumHelp' => $helpLinks->primary(HelpFeature::Premium, 'premium_access', $locale, $this->locale),
        ])->extends('layouts.app', [
            'title' => __('premium.pricing_title'),
            'seo' => [
                'title' => __('premium.pricing_title'),
                'description' => __('premium.pricing_description'),
                'robots' => $publicPlans === [] ? 'noindex, follow' : 'index, follow',
                'canonical' => $canonical,
                'social' => $publicPlans !== [],
                'alternates' => [],
                'jsonLd' => [],
            ],
        ])->section('content');
    }

    private function pricingUrl(?string $plan = null): string
    {
        $route = $this->locale !== null ? 'localized.premium.index' : 'premium.index';

        return route($route, array_filter([
            'locale' => $this->locale,
            'plan' => $plan,
        ]));
    }
}
