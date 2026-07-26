<?php

declare(strict_types=1);

namespace Tests\Feature\Pwa;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PwaActionSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_queue_endpoint_requires_an_authenticated_active_account(): void
    {
        $this->postJson('/pwa/actions', ['operations' => [$this->watchlist('missing', true)]])
            ->assertUnauthorized();
    }

    public function test_action_queue_accepts_only_strict_bounded_title_state_operations(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $invalid = [
            [[], 'operations'],
            [['operations' => []], 'operations'],
            [['operations' => ['named' => $this->watchlist('title', true)]], 'operations'],
            [['operations' => array_fill(0, 51, $this->watchlist('title', true))], 'operations'],
            [['operations' => [[
                'mutation_id' => (string) Str::uuid(),
                'type' => 'history.clear',
            ]]], 'operations.0.type'],
            [['operations' => [[
                ...$this->watchlist('title', true),
                'unexpected' => 'private',
            ]]], 'operations.0.unexpected'],
            [['operations' => [$this->watchlist('title', 1)]], 'operations.0.value'],
            [['operations' => [$this->rating('title', 11)]], 'operations.0.value'],
            [['operations' => [$this->rating('title', '9')]], 'operations.0.value'],
            [['operations' => [[
                ...$this->watchlist('title', true),
                'expected_version' => -1,
            ]]], 'operations.0.expected_version'],
        ];

        foreach ($invalid as [$payload, $field]) {
            $this->postJson('/pwa/actions', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }
    }

    public function test_action_queue_reuses_canonical_idempotent_versioned_sync(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create(['slug' => 'pwa-action-title']);
        $watchlist = $this->watchlist($title->slug, true);
        $rating = $this->rating($title->slug, 8);

        $response = $this->actingAs($user)
            ->postJson('/pwa/actions', ['operations' => [$watchlist, $rating]])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.0.resource_version', 1)
            ->assertJsonPath('data.results.1.status', 'applied')
            ->assertJsonPath('data.results.1.resource_version', 1);

        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->actingAs($user)
            ->postJson('/pwa/actions', ['operations' => [$watchlist, $rating]])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'duplicate')
            ->assertJsonPath('data.results.1.status', 'duplicate');

        $this->actingAs($user)
            ->postJson('/pwa/actions', ['operations' => [[
                ...$this->watchlist($title->slug, false),
                'expected_version' => 0,
            ]]])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'conflict')
            ->assertJsonPath('data.results.0.resource_version', 1);

        $state = CatalogTitleUserState::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($title)
            ->sole();

        $this->assertTrue($state->in_watchlist);
        $this->assertSame(8, $state->rating);
    }

    /** @return array<string, mixed> */
    private function watchlist(string $slug, mixed $value): array
    {
        return [
            'mutation_id' => (string) Str::uuid(),
            'type' => 'watchlist.set',
            'title_slug' => $slug,
            'value' => $value,
            'expected_version' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function rating(string $slug, mixed $value): array
    {
        return [
            'mutation_id' => (string) Str::uuid(),
            'type' => 'rating.set',
            'title_slug' => $slug,
            'value' => $value,
            'expected_version' => 0,
        ];
    }
}
