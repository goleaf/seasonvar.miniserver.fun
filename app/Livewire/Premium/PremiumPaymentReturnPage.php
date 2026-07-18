<?php

declare(strict_types=1);

namespace App\Livewire\Premium;

use App\Enums\PremiumCheckoutStatus;
use App\Models\PremiumCheckoutSession;
use App\Models\User;
use App\ValueObjects\Money;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class PremiumPaymentReturnPage extends Component
{
    #[Locked]
    public string $checkout = '';

    #[Locked]
    public bool $browserCancelled = false;

    #[Locked]
    public ?string $locale = null;

    public function mount(string $checkout, ?string $locale = null): void
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $checkout) === 1, 404);
        abort_if($locale !== null && ! in_array($locale, (array) config('catalog-collections.supported_locales', []), true), 404);
        $this->checkout = $checkout;
        $this->locale = $locale;
        $this->browserCancelled = request()->string('result')->value() === 'cancelled';
    }

    public function render(): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $session = PremiumCheckoutSession::query()
            ->whereBelongsTo($user)
            ->where('public_id', $this->checkout)
            ->firstOrFail();
        $state = match (true) {
            $session->status === PremiumCheckoutStatus::Succeeded => 'succeeded',
            $session->status === PremiumCheckoutStatus::Failed => 'failed',
            $session->status === PremiumCheckoutStatus::Expired || $session->expires_at?->isPast() === true => 'expired',
            $this->browserCancelled => 'cancelled',
            default => 'pending',
        };
        $localized = $this->locale !== null;

        return view('livewire.premium.payment-return-page', [
            'state' => $state,
            'amount' => Money::from($session->amount_minor, $session->currency)->format(app()->getLocale()),
            'pricingUrl' => route($localized ? 'localized.premium.index' : 'premium.index', array_filter(['locale' => $this->locale])),
            'settingsUrl' => route($localized ? 'localized.settings.index' : 'settings.index', array_filter(['locale' => $this->locale, 'section' => 'premium'])),
        ])->extends('layouts.app', [
            'title' => __('premium.return.title'),
            'seo' => [
                'title' => __('premium.return.title'),
                'description' => __('premium.return.description'),
                'robots' => 'noindex, nofollow, noarchive',
                'canonical' => route($localized ? 'localized.premium.return' : 'premium.return', array_filter(['locale' => $this->locale, 'checkout' => $session->public_id])),
                'social' => false,
                'alternates' => [],
                'jsonLd' => [],
            ],
        ])->section('content');
    }
}
