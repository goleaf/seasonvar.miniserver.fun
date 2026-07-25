<?php

declare(strict_types=1);

namespace App\Livewire\Premium;

use App\Enums\PremiumEntitlementSource;
use App\Enums\PremiumFeature;
use App\Enums\PremiumGrantReason;
use App\Livewire\Concerns\InteractsWithPaginationIslands;
use App\Models\PremiumEntitlement;
use App\Models\PremiumPromotion;
use App\Models\User;
use App\Services\Premium\PremiumAdministrationQuery;
use App\Services\Premium\PremiumEntitlementService;
use App\Services\Premium\PremiumPaymentGatewayRegistry;
use App\Services\Premium\PremiumPromotionService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

final class PremiumAdministrationManager extends Component
{
    use InteractsWithPaginationIslands;
    use WithPagination;

    public string $userSearch = '';

    #[Locked]
    public string $selectedUserPublicId = '';

    public int $durationDays = 30;

    public bool $lifetime = false;

    public string $reason = 'support_compensation';

    public string $privateNote = '';

    public string $promotionCode = '';

    public int $promotionDurationDays = 7;

    public string $promotionStartsAt = '';

    public string $promotionEndsAt = '';

    public string $promotionTotalLimit = '';

    public int $promotionPerUserLimit = 1;

    public string $statusMessage = '';

    public string $actionError = '';

    public function mount(): void
    {
        Gate::authorize('view-premium-administration');
    }

    public function findUser(PremiumAdministrationQuery $query): void
    {
        Gate::authorize('manage-premium-grants');
        $this->throttleAdministration();
        $validated = $this->validate(['userSearch' => ['required', 'string', 'max:191']]);
        $identity = trim($validated['userSearch']);
        $user = $query->findUser($identity);

        if ($user === null) {
            throw ValidationException::withMessages(['userSearch' => [__('premium.errors.user_not_found')]]);
        }

        $this->selectedUserPublicId = $user['public_id'];
        $this->userSearch = $user['email'];
        $this->statusMessage = '';
        $this->actionError = '';
    }

    public function grant(PremiumEntitlementService $entitlements): void
    {
        Gate::authorize('manage-premium-grants');
        $this->throttleAdministration();
        $validated = $this->validate([
            'durationDays' => ['required_unless:lifetime,true', 'integer', 'between:1,3650'],
            'lifetime' => ['boolean'],
            'reason' => ['required', Rule::enum(PremiumGrantReason::class)],
            'privateNote' => ['nullable', 'string', 'max:1000'],
        ]);
        $target = $this->selectedUser();
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $reason = PremiumGrantReason::from($validated['reason']);
        $source = match ($reason) {
            PremiumGrantReason::SupportCompensation => PremiumEntitlementSource::SupportCompensation,
            PremiumGrantReason::MigrationCorrection => PremiumEntitlementSource::AccountMigration,
            default => PremiumEntitlementSource::ManualGrant,
        };
        $identity = 'admin-grant:'.Str::uuid();

        if ($validated['lifetime']) {
            $entitlements->grantLifetime(
                $target,
                PremiumFeature::PremiumAccess,
                $source,
                $identity,
                $actor,
                $reason->value,
                $validated['privateNote'] ?: null,
            );
        } else {
            $entitlements->grantDuration(
                $target,
                PremiumFeature::PremiumAccess,
                $source,
                $validated['durationDays'],
                $identity,
                $actor,
                $reason->value,
                $validated['privateNote'] ?: null,
            );
        }

        $this->reset('privateNote');
        $this->statusMessage = __('premium.admin.granted');
        $this->actionError = '';
    }

    public function revoke(string $entitlementPublicId, PremiumEntitlementService $entitlements): void
    {
        Gate::authorize('manage-premium-grants');
        $this->throttleAdministration();
        abort_unless(Str::isUuid($entitlementPublicId), 404);
        $target = $this->selectedUser();
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $entitlement = PremiumEntitlement::query()
            ->whereBelongsTo($target)
            ->where('public_id', $entitlementPublicId)
            ->whereNull('revoked_at')
            ->whereIn('source', [
                PremiumEntitlementSource::ManualGrant->value,
                PremiumEntitlementSource::SupportCompensation->value,
                PremiumEntitlementSource::AccountMigration->value,
                PremiumEntitlementSource::Promotion->value,
            ])
            ->firstOrFail();
        $entitlements->revokeAdministrative($entitlement, $actor, 'administrative_correction');
        $this->statusMessage = __('premium.admin.revoked');
        $this->actionError = '';
    }

    public function createPromotion(PremiumPromotionService $promotions): void
    {
        Gate::authorize('manage-premium-promotions');
        $this->throttleAdministration();
        $validated = $this->validate([
            'promotionCode' => ['required', 'string', 'max:64', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_-]{2,63}\z/'],
            'promotionDurationDays' => ['required', 'integer', 'between:1,3650'],
            'promotionStartsAt' => ['nullable', 'date'],
            'promotionEndsAt' => ['nullable', 'date', 'after:promotionStartsAt'],
            'promotionTotalLimit' => ['nullable', 'integer', 'min:1'],
            'promotionPerUserLimit' => ['required', 'integer', 'between:1,20'],
        ]);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $promotions->createPromotion(
            $actor,
            $validated['promotionCode'],
            $validated['promotionDurationDays'],
            $validated['promotionStartsAt'] !== '' ? CarbonImmutable::parse($validated['promotionStartsAt'], (string) config('app.timezone'))->utc() : null,
            $validated['promotionEndsAt'] !== '' ? CarbonImmutable::parse($validated['promotionEndsAt'], (string) config('app.timezone'))->utc() : null,
            $validated['promotionTotalLimit'] !== '' ? (int) $validated['promotionTotalLimit'] : null,
            $validated['promotionPerUserLimit'],
        );
        $this->reset('promotionCode', 'promotionStartsAt', 'promotionEndsAt', 'promotionTotalLimit');
        $this->statusMessage = __('premium.admin.promotion_created');
        $this->actionError = '';
    }

    public function createCoupon(string $promotionPublicId, PremiumPromotionService $promotions): void
    {
        Gate::authorize('manage-premium-promotions');
        $this->throttleAdministration();
        abort_unless(Str::isUuid($promotionPublicId), 404);
        $promotion = PremiumPromotion::query()->where('public_id', $promotionPublicId)->firstOrFail();
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $created = $promotions->createCoupon($actor, $promotion, 1);
        $this->statusMessage = __('premium.admin.coupon_created', ['code' => $created['code']]);
        $this->actionError = '';
    }

    public function render(
        PremiumAdministrationQuery $query,
        PremiumPaymentGatewayRegistry $gateways,
    ): View {
        Gate::authorize('view-premium-administration');
        $canManageGrants = Gate::allows('manage-premium-grants');
        $canManagePromotions = Gate::allows('manage-premium-promotions');
        $canViewAudit = Gate::allows('view-premium-billing-audit');
        $page = $query->page(
            $this->selectedUserPublicId,
            $canManageGrants,
            $canManagePromotions,
            $canViewAudit,
        );

        return view('livewire.premium.administration-manager', [
            'schemaReady' => $page['schema_ready'],
            'selectedUser' => $page['selected_user'],
            'entitlements' => $page['entitlements'],
            'promotions' => $page['promotions'],
            'audits' => $page['audits'],
            'reasonOptions' => collect(PremiumGrantReason::cases())->map(fn (PremiumGrantReason $reason): array => ['value' => $reason->value, 'label' => $reason->label()])->all(),
            'providerCodes' => $gateways->codes(),
            'canManageGrants' => $canManageGrants,
            'canManagePromotions' => $canManagePromotions,
            'canViewAudit' => $canViewAudit,
        ])->extends('layouts.app', [
            'title' => __('premium.admin.title'),
            'seo' => ['title' => __('premium.admin.title'), 'description' => __('premium.admin.description'), 'robots' => 'noindex, nofollow, noarchive', 'canonical' => route('admin.premium'), 'social' => false, 'alternates' => [], 'jsonLd' => []],
        ])->section('content');
    }

    private function selectedUser(): User
    {
        if ($this->selectedUserPublicId === '') {
            throw ValidationException::withMessages(['userSearch' => [__('premium.errors.user_not_found')]]);
        }

        return User::query()->where('public_id', $this->selectedUserPublicId)->firstOrFail();
    }

    private function throttleAdministration(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        if (! RateLimiter::attempt(
            'premium-administration:user:'.$actor->id,
            max(1, (int) config('premium.rate_limits.administration_per_minute', 30)),
            static fn (): bool => true,
            60,
        )) {
            throw ValidationException::withMessages(['userSearch' => [__('premium.errors.administration_rate_limited')]]);
        }
    }
}
