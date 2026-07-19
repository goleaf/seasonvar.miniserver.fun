<?php

declare(strict_types=1);

namespace App\Actions\Administration;

use App\Enums\AdminAuditAction;
use App\Enums\AdminPermission;
use App\Exceptions\AdministrationAccessException;
use App\Models\AdminOperationalEvent;
use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Admin\AdminAuditRecorder;
use App\Services\Catalog\Search\CatalogSearchIndexer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ReindexCatalogResource
{
    public function __construct(
        private CatalogSearchIndexer $indexer,
        private AdminAuditRecorder $audit,
    ) {}

    public function handle(User $actor, string $slug, bool $confirmed, ?string $idempotencyIdentity = null): AdminOperationalEvent
    {
        Gate::forUser($actor)->authorize(AdminPermission::SearchReindex->value);

        if (! $confirmed) {
            throw new AdministrationAccessException('administration.errors.explicit_confirmation_required');
        }

        $slug = trim($slug);

        if ($slug === '' || mb_strlen($slug) > 255 || str_contains($slug, '/')) {
            throw new InvalidArgumentException('A single canonical catalogue slug is required.');
        }

        $title = CatalogTitle::query()->select(['id', 'slug'])->where('slug', $slug)->firstOrFail();
        $idempotencyKey = hash('sha256', $actor->id.'|search|'.$title->id.'|'.($idempotencyIdentity ?? Str::uuid()->toString()));
        $existing = AdminOperationalEvent::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing instanceof AdminOperationalEvent) {
            return $existing;
        }

        $result = $this->indexer->indexTitleIds([$title->id], 1);
        $event = AdminOperationalEvent::query()->create([
            'actor_id' => $actor->id,
            'action_code' => 'search.resource_reindex',
            'target_code' => 'catalog_title:'.$title->slug,
            'status' => 'completed',
            'result_summary' => array_intersect_key($result, array_flip(['requested', 'indexed', 'unchanged', 'deleted'])),
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => now(),
        ]);
        $this->audit->record(
            $actor,
            AdminAuditAction::SearchResourceReindexed,
            $event,
            AdminAuditRecorder::ABSENT_VERSION,
            $this->fingerprint($event),
            ['index_version'],
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
