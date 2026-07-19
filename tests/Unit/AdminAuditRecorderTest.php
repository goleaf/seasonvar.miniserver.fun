<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AdminAuditAction;
use App\Models\AdminAuditEvent;
use App\Models\CatalogTitle;
use App\Models\Source;
use App\Models\User;
use App\Services\Admin\AdminAuditRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class AdminAuditRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_is_additive_and_reversible(): void
    {
        $this->assertTrue(Schema::hasTable('admin_audit_events'));
        $this->assertTrue(Schema::hasColumns('admin_audit_events', [
            'id',
            'actor_id',
            'action',
            'resource_type',
            'resource_id',
            'before_version',
            'after_version',
            'changed_fields',
            'occurred_at',
        ]));

        $migration = require database_path('migrations/2026_07_13_210000_create_admin_audit_events_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('admin_audit_events'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('admin_audit_events'));
    }

    public function test_recorder_stores_only_allowlisted_sorted_field_names_and_version_fingerprints(): void
    {
        $actor = User::factory()->create();
        $sourceUrl = 'https://seasonvar.ru/serial-99100-Audit-secret-1-season.html';
        $resource = CatalogTitle::factory()->create([
            'title' => 'Секретное значение поля',
            'source_url' => $sourceUrl,
            'source_url_hash' => hash('sha256', $sourceUrl),
        ]);
        $before = hash('sha256', 'before-version');
        $after = hash('sha256', 'after-version');

        app(AdminAuditRecorder::class)->record(
            $actor,
            AdminAuditAction::TitleUpdated,
            $resource,
            $before,
            $after,
            ['year', 'title', 'year'],
        );

        $event = AdminAuditEvent::query()->sole();
        $rawRow = json_encode((array) DB::table('admin_audit_events')->first(), JSON_THROW_ON_ERROR);

        $this->assertTrue($event->actor->is($actor));
        $this->assertSame(AdminAuditAction::TitleUpdated, $event->action);
        $this->assertSame('catalog_title', $event->resource_type);
        $this->assertSame($resource->id, $event->resource_id);
        $this->assertSame($before, $event->before_version);
        $this->assertSame($after, $event->after_version);
        $this->assertSame(['title', 'year'], $event->changed_fields);
        $this->assertNotNull($event->occurred_at);
        $this->assertStringNotContainsString('Секретное значение поля', $rawRow);
        $this->assertStringNotContainsString($sourceUrl, $rawRow);
        $this->assertStringNotContainsString('token', mb_strtolower($rawRow));
    }

    public function test_recorder_rejects_unknown_fields_resource_types_and_invalid_versions(): void
    {
        $actor = User::factory()->create();
        $title = CatalogTitle::factory()->create();
        $source = Source::factory()->create();
        $version = hash('sha256', 'valid');
        $recorder = app(AdminAuditRecorder::class);

        $this->assertThrows(
            fn () => $recorder->record(
                $actor,
                AdminAuditAction::TitleUpdated,
                $title,
                $version,
                $version,
                ['title', 'raw_payload'],
            ),
            InvalidArgumentException::class,
        );
        $this->assertThrows(
            fn () => $recorder->record(
                $actor,
                AdminAuditAction::TitleUpdated,
                $source,
                $version,
                $version,
                ['title'],
            ),
            InvalidArgumentException::class,
        );
        $this->assertThrows(
            fn () => $recorder->record(
                $actor,
                AdminAuditAction::TitleUpdated,
                $title,
                'not-a-version',
                $version,
                ['title'],
            ),
            InvalidArgumentException::class,
        );

        $this->assertDatabaseCount('admin_audit_events', 0);
    }

    public function test_audit_events_cannot_be_updated_or_deleted_through_the_model(): void
    {
        $actor = User::factory()->create();
        $title = CatalogTitle::factory()->create();
        $version = hash('sha256', 'version');
        app(AdminAuditRecorder::class)->record(
            $actor,
            AdminAuditAction::TitleUpdated,
            $title,
            $version,
            $version,
            ['title'],
        );
        $event = AdminAuditEvent::query()->sole();

        $this->assertThrows(
            fn (): bool => $event->update(['action' => AdminAuditAction::TitleArchived]),
            LogicException::class,
        );
        $event->refresh();
        $this->assertThrows(
            fn (): ?bool => $event->delete(),
            LogicException::class,
        );

        $this->assertDatabaseCount('admin_audit_events', 1);
    }

    public function test_correlation_identity_accepts_only_a_bounded_uuid_and_never_raw_request_data(): void
    {
        $actor = User::factory()->create();
        $first = CatalogTitle::factory()->create();
        $second = CatalogTitle::factory()->create();
        $version = hash('sha256', 'correlation-version');
        $correlationId = (string) Str::uuid();

        request()->attributes->set('request_id', 'secret-token-that-must-not-be-stored');
        app(AdminAuditRecorder::class)->record($actor, AdminAuditAction::TitleUpdated, $first, $version, $version, ['title']);

        request()->attributes->set('request_id', $correlationId);
        app(AdminAuditRecorder::class)->record($actor, AdminAuditAction::TitleUpdated, $second, $version, $version, ['title']);

        $events = AdminAuditEvent::query()->orderBy('id')->get();
        $this->assertNull($events[0]->correlation_id);
        $this->assertSame($correlationId, $events[1]->correlation_id);
    }
}
