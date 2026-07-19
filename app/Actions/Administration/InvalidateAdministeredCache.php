<?php

declare(strict_types=1);

namespace App\Actions\Administration;

use App\Enums\AdminAuditAction;
use App\Enums\AdminPermission;
use App\Exceptions\AdministrationAccessException;
use App\Models\AdminOperationalEvent;
use App\Models\User;
use App\Services\Admin\AdminAuditRecorder;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class InvalidateAdministeredCache
{
    /** @var list<CacheDomain> */
    private const ALLOWED_DOMAINS = [
        CacheDomain::Homepage, CacheDomain::CatalogPages, CacheDomain::CatalogFacets,
        CacheDomain::CatalogStats, CacheDomain::TitleDetail, CacheDomain::Recommendations,
        CacheDomain::SearchSuggestions, CacheDomain::Tags, CacheDomain::Collections,
        CacheDomain::ContentRequests, CacheDomain::ReleaseCalendar, CacheDomain::HelpCenter,
        CacheDomain::Sitemap, CacheDomain::Api,
    ];

    public function __construct(
        private CacheVersionRegistry $versions,
        private AdminAuditRecorder $audit,
    ) {}

    /** @return list<CacheDomain> */
    public static function domains(): array
    {
        return self::ALLOWED_DOMAINS;
    }

    public function handle(User $actor, string $domainCode, bool $confirmed, ?string $idempotencyIdentity = null): AdminOperationalEvent
    {
        Gate::forUser($actor)->authorize(AdminPermission::CacheInvalidate->value);

        if (! $confirmed) {
            throw new AdministrationAccessException('administration.errors.explicit_confirmation_required');
        }

        $domain = CacheDomain::tryFrom($domainCode);

        if ($domain === null || ! in_array($domain, self::ALLOWED_DOMAINS, true)) {
            throw new InvalidArgumentException('Only an allowlisted public cache domain can be invalidated.');
        }

        $idempotencyKey = hash('sha256', $actor->id.'|cache|'.$domain->value.'|'.($idempotencyIdentity ?? Str::uuid()->toString()));
        $existing = AdminOperationalEvent::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing instanceof AdminOperationalEvent) {
            return $existing;
        }

        $version = $this->versions->bump($domain);
        $event = AdminOperationalEvent::query()->create([
            'actor_id' => $actor->id,
            'action_code' => 'cache.invalidate',
            'target_code' => $domain->value,
            'status' => 'completed',
            'result_summary' => ['version' => $version],
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => now(),
        ]);
        $this->audit->record(
            $actor,
            AdminAuditAction::CacheInvalidated,
            $event,
            AdminAuditRecorder::ABSENT_VERSION,
            $this->fingerprint($event),
            ['cache_version'],
        );

        return $event;
    }

    private function fingerprint(AdminOperationalEvent $event): string
    {
        return hash('sha256', json_encode([
            'action' => $event->action_code,
            'target' => $event->target_code,
            'status' => $event->status,
            'result' => $event->result_summary,
        ], JSON_THROW_ON_ERROR));
    }
}
