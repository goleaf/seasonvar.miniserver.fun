<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\Enums\PremiumEntitlementSource;
use App\Models\PremiumAuditEvent;
use App\Models\PremiumEntitlement;
use App\Models\PremiumPromotion;
use App\Models\User;
use App\ValueObjects\NormalizedEmail;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class PremiumAdministrationQuery
{
    private const AUDIT_PAGE_NAME = 'premiumAuditPage';

    private const AUDIT_PER_PAGE = 20;

    private const ENTITLEMENT_LIMIT = 30;

    private const PROMOTION_LIMIT = 20;

    public function __construct(private readonly PremiumSchema $schema) {}

    /**
     * @return array{public_id: string, name: string, email: string}|null
     */
    public function findUser(string $identity): ?array
    {
        $identity = trim($identity);
        $columns = ['public_id', 'name', 'email'];

        if (Str::isUuid($identity)) {
            $user = User::query()
                ->select($columns)
                ->where('public_id', $identity)
                ->first();

            return $user instanceof User ? $this->foundUser($user) : null;
        }

        $email = NormalizedEmail::value($identity);
        $user = User::query()
            ->select($columns)
            ->where('email', $email)
            ->first();

        if (! $user instanceof User) {
            $user = User::query()
                ->select($columns)
                ->whereEmailIdentity($email)
                ->first();
        }

        return $user instanceof User ? $this->foundUser($user) : null;
    }

    /**
     * @return array{
     *   schema_ready: bool,
     *   selected_user: array{name: string, email: string}|null,
     *   entitlements: Collection<int, array{
     *     public_id: string,
     *     feature: string,
     *     source: string,
     *     period: string,
     *     can_revoke: bool
     *   }>,
     *   promotions: Collection<int, array{
     *     public_id: string,
     *     code: string,
     *     redemptions: int,
     *     limit: string,
     *     duration: string
     *   }>,
     *   audits: LengthAwarePaginator<int, array{
     *     action: string,
     *     occurred_at: string,
     *     actor: string,
     *     resource_type: string
     *   }>
     * }
     */
    public function page(
        string $selectedUserPublicId,
        bool $canManageGrants,
        bool $canManagePromotions,
        bool $canViewAudit,
    ): array {
        $schemaReady = $this->schema->ready();
        $selected = $canManageGrants && $selectedUserPublicId !== ''
            ? User::query()
                ->select(['id', 'name', 'email'])
                ->where('public_id', $selectedUserPublicId)
                ->first()
            : null;

        return [
            'schema_ready' => $schemaReady,
            'selected_user' => $selected instanceof User
                ? ['name' => (string) $selected->name, 'email' => (string) $selected->email]
                : null,
            'entitlements' => $schemaReady && $selected instanceof User
                ? $this->entitlements($selected)
                : collect(),
            'promotions' => $schemaReady && $canManagePromotions
                ? $this->promotions()
                : collect(),
            'audits' => $schemaReady && $canViewAudit
                ? $this->audits()
                : $this->emptyAudits(),
        ];
    }

    /**
     * @return array{public_id: string, name: string, email: string}
     */
    private function foundUser(User $user): array
    {
        return [
            'public_id' => (string) $user->public_id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
        ];
    }

    /**
     * @return Collection<int, array{
     *   public_id: string,
     *   feature: string,
     *   source: string,
     *   period: string,
     *   can_revoke: bool
     * }>
     */
    private function entitlements(User $user): Collection
    {
        return PremiumEntitlement::query()
            ->select([
                'id', 'public_id', 'user_id', 'feature_code', 'source',
                'starts_at', 'ends_at', 'is_lifetime', 'revoked_at',
            ])
            ->whereBelongsTo($user)
            ->latest('starts_at')
            ->latest('id')
            ->limit(self::ENTITLEMENT_LIMIT)
            ->get()
            ->map($this->entitlementData(...));
    }

    /**
     * @return Collection<int, array{
     *   public_id: string,
     *   code: string,
     *   redemptions: int,
     *   limit: string,
     *   duration: string
     * }>
     */
    private function promotions(): Collection
    {
        return PremiumPromotion::query()
            ->select(['id', 'public_id', 'code', 'duration_days', 'total_limit', 'created_at'])
            ->withCount('redemptions')
            ->latest('created_at')
            ->latest('id')
            ->limit(self::PROMOTION_LIMIT)
            ->get()
            ->map($this->promotionData(...));
    }

    /**
     * @return LengthAwarePaginator<int, array{
     *   action: string,
     *   occurred_at: string,
     *   actor: string,
     *   resource_type: string
     * }>
     */
    private function audits(): LengthAwarePaginator
    {
        return PremiumAuditEvent::query()
            ->select(['id', 'actor_id', 'action', 'resource_type', 'occurred_at'])
            ->with('actor:id,name')
            ->latest('occurred_at')
            ->latest('id')
            ->paginate(
                perPage: self::AUDIT_PER_PAGE,
                pageName: self::AUDIT_PAGE_NAME,
            )
            ->through($this->auditData(...));
    }

    /**
     * @return array{
     *   public_id: string,
     *   feature: string,
     *   source: string,
     *   period: string,
     *   can_revoke: bool
     * }
     */
    private function entitlementData(PremiumEntitlement $entitlement): array
    {
        return [
            'public_id' => $entitlement->public_id,
            'feature' => $entitlement->feature_code->label(),
            'source' => $entitlement->source->label(),
            'period' => $entitlement->is_lifetime
                ? $this->translation('premium.settings.lifetime')
                : $this->translation('premium.settings.active_until', [
                    'date' => $entitlement->ends_at?->translatedFormat('j F Y, H:i'),
                ]),
            'can_revoke' => $entitlement->revoked_at === null
                && ($entitlement->source->isAdministrative()
                    || $entitlement->source === PremiumEntitlementSource::Promotion),
        ];
    }

    /**
     * @return array{
     *   public_id: string,
     *   code: string,
     *   redemptions: int,
     *   limit: string,
     *   duration: string
     * }
     */
    private function promotionData(PremiumPromotion $promotion): array
    {
        return [
            'public_id' => $promotion->public_id,
            'code' => $promotion->code,
            'redemptions' => $promotion->redemptions_count,
            'limit' => $promotion->total_limit !== null ? (string) $promotion->total_limit : '∞',
            'duration' => trans_choice(
                'premium.duration_days',
                $promotion->duration_days,
                ['count' => $promotion->duration_days],
            ),
        ];
    }

    /**
     * @return array{
     *   action: string,
     *   occurred_at: string,
     *   actor: string,
     *   resource_type: string
     * }
     */
    private function auditData(PremiumAuditEvent $event): array
    {
        return [
            'action' => $event->action->label(),
            'occurred_at' => $event->occurred_at->translatedFormat('j F Y, H:i'),
            'actor' => $event->actor_id !== null
                ? (string) $event->actor->name
                : $this->translation('premium.admin.system_actor'),
            'resource_type' => $event->resource_type,
        ];
    }

    /** @param array<string, mixed> $replace */
    private function translation(string $key, array $replace = []): string
    {
        $translated = __($key, $replace);

        return is_string($translated) ? $translated : $key;
    }

    /**
     * @return LengthAwarePaginator<int, array{
     *   action: string,
     *   occurred_at: string,
     *   actor: string,
     *   resource_type: string
     * }>
     */
    private function emptyAudits(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            items: [],
            total: 0,
            perPage: self::AUDIT_PER_PAGE,
            options: ['pageName' => self::AUDIT_PAGE_NAME],
        );
    }
}
