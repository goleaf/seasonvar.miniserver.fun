<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OfflineSyncRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_removes_only_expired_rows_in_bounded_chunks(): void
    {
        Carbon::setTestNow('2026-07-14 18:00:00');
        config()->set('mobile-api.sync.change_retention_days', 30);
        config()->set('mobile-api.sync.mutation_retention_days', 90);

        $user = User::factory()->create();
        $expiredChangeIds = $this->insertChanges(501, now()->subDays(31));
        $freshChangeIds = $this->insertChanges(2, now()->subDays(29));
        $expiredMutationIds = $this->insertMutations($user, 501, now()->subDays(91));
        $freshMutationIds = $this->insertMutations($user, 2, now()->subDays(89));
        $deleteBindingCounts = [];

        DB::listen(function (QueryExecuted $query) use (&$deleteBindingCounts): void {
            if (str_starts_with(strtolower($query->sql), 'delete from "api_sync_')) {
                $deleteBindingCounts[] = count($query->bindings);
            }
        });

        $this->artisan('api:sync-prune')
            ->expectsOutputToContain('Изменения синхронизации: 501')
            ->expectsOutputToContain('Квитанции операций: 501')
            ->assertSuccessful();

        $this->assertSame([], DB::table('api_sync_changes')->whereIn('id', $expiredChangeIds)->pluck('id')->all());
        $this->assertEqualsCanonicalizing($freshChangeIds, DB::table('api_sync_changes')->pluck('id')->all());
        $this->assertSame([], DB::table('api_sync_mutations')->whereIn('id', $expiredMutationIds)->pluck('id')->all());
        $this->assertEqualsCanonicalizing($freshMutationIds, DB::table('api_sync_mutations')->pluck('id')->all());
        $this->assertCount(4, $deleteBindingCounts);
        $this->assertLessThanOrEqual(500, max($deleteBindingCounts));
    }

    public function test_prune_is_a_successful_no_op_before_sync_schema_is_installed(): void
    {
        Schema::drop('api_sync_mutations');
        Schema::drop('api_sync_changes');

        $this->artisan('api:sync-prune')
            ->expectsOutputToContain('Схема синхронизации ещё не установлена')
            ->assertSuccessful();
    }

    public function test_prune_is_scheduled_daily_with_single_server_and_overlap_guards(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => $event->description === 'api-sync-prune');

        $this->assertNotNull($event);
        $this->assertSame('23 3 * * *', $event->expression);
        $this->assertStringContainsString('api:sync-prune', $event->command);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
        $this->assertTrue($event->onOneServer);
        $this->assertSame('redis-locks', $event->mutex->store);
    }

    /** @return list<int> */
    private function insertChanges(int $count, Carbon $changedAt): array
    {
        $firstId = (int) DB::table('api_sync_changes')->max('id') + 1;
        $rows = [];

        foreach (range(1, $count) as $offset) {
            $rows[] = [
                'scope' => 'catalog',
                'user_id' => null,
                'resource_type' => 'title',
                'resource_key' => 'retention-title-'.$offset.'-'.$changedAt->day,
                'operation' => 'upsert',
                'changed_at' => $changedAt,
                'created_at' => $changedAt,
                'updated_at' => $changedAt,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('api_sync_changes')->insert($chunk);
        }

        return range($firstId, $firstId + $count - 1);
    }

    /** @return list<int> */
    private function insertMutations(User $user, int $count, Carbon $createdAt): array
    {
        $firstId = (int) DB::table('api_sync_mutations')->max('id') + 1;
        $rows = [];

        foreach (range(1, $count) as $offset) {
            $rows[] = [
                'user_id' => $user->id,
                'mutation_id' => (string) Str::uuid(),
                'payload_hash' => hash('sha256', "retention-{$createdAt->timestamp}-{$offset}"),
                'status' => 'applied',
                'result' => '{}',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('api_sync_mutations')->insert($chunk);
        }

        return range($firstId, $firstId + $count - 1);
    }
}
