<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\DTOs\Premium\PremiumAccessSummary;
use App\Enums\PremiumEntitlementSource;
use App\Enums\PremiumFeature;
use App\Models\PremiumEntitlement;
use App\Models\User;
use Carbon\CarbonImmutable;

final class PremiumAccessResolver
{
    /** @var array<int, PremiumAccessSummary> */
    private array $resolved = [];

    public function __construct(private readonly PremiumSchema $schema) {}

    public function resolve(?User $user): PremiumAccessSummary
    {
        if (! $user instanceof User || ! $this->schema->ready()) {
            return PremiumAccessSummary::inactive();
        }

        return $this->resolved[$user->id] ??= $this->query($user);
    }

    public function has(?User $user, PremiumFeature $feature): bool
    {
        $summary = $this->resolve($user);

        return $summary->active && in_array($feature->value, $summary->features, true);
    }

    public function forget(User $user): void
    {
        unset($this->resolved[$user->id]);
    }

    private function query(User $user): PremiumAccessSummary
    {
        $now = CarbonImmutable::now();
        $entitlements = PremiumEntitlement::query()
            ->whereBelongsTo($user)
            ->activeAt($now)
            ->with('subscription:id,status,current_period_end,grace_ends_at,cancel_at_period_end')
            ->orderBy('starts_at')
            ->get([
                'id', 'user_id', 'premium_subscription_id', 'feature_code', 'source',
                'starts_at', 'ends_at', 'is_lifetime',
            ]);

        if ($entitlements->isEmpty()) {
            return PremiumAccessSummary::inactive();
        }

        $lifetime = $entitlements->contains(fn (PremiumEntitlement $entitlement): bool => $entitlement->is_lifetime);
        $subscriptions = $entitlements->filter(fn (PremiumEntitlement $entitlement): bool => $entitlement->source === PremiumEntitlementSource::Subscription);
        $expiresAt = $entitlements->reduce(
            static function (?CarbonImmutable $latest, PremiumEntitlement $entitlement) use ($now): ?CarbonImmutable {
                $candidate = $entitlement->effectiveEndsAt($now);

                return $candidate !== null && ($latest === null || $candidate->greaterThan($latest)) ? $candidate : $latest;
            },
        );
        $manualSources = [
            PremiumEntitlementSource::ManualGrant,
            PremiumEntitlementSource::SupportCompensation,
            PremiumEntitlementSource::AccountMigration,
        ];

        return new PremiumAccessSummary(
            active: true,
            startsAt: $entitlements->min('starts_at'),
            expiresAt: $lifetime ? null : $expiresAt,
            lifetime: $lifetime,
            manual: $entitlements->contains(fn (PremiumEntitlement $entitlement): bool => in_array($entitlement->source, $manualSources, true)),
            subscription: $subscriptions->isNotEmpty(),
            gracePeriod: $subscriptions->contains(fn (PremiumEntitlement $entitlement): bool => $entitlement->graceActiveAt($now)),
            cancellationScheduled: $subscriptions->contains(fn (PremiumEntitlement $entitlement): bool => $entitlement->premium_subscription_id !== null
                && $entitlement->subscription->cancel_at_period_end
                && $entitlement->subscription->current_period_end?->isFuture() === true),
            regionalRestrictionsApply: true,
            features: $entitlements->pluck('feature_code')->map->value->unique()->sort()->values()->all(),
            sources: $entitlements->pluck('source')->map->value->unique()->sort()->values()->all(),
        );
    }
}
