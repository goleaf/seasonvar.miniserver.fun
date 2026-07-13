<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RateLimiting\RequestRateLimitKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class RequestRateLimitKeyTest extends TestCase
{
    public function test_actor_key_never_contains_a_raw_user_id_or_ip_address(): void
    {
        $request = Request::create('/api/titles', 'GET', server: ['REMOTE_ADDR' => '203.0.113.45']);
        $request->setUserResolver(fn (): object => new class
        {
            public function getAuthIdentifier(): int
            {
                return 987654;
            }
        });

        $key = app(RequestRateLimitKey::class)->actor($request);

        $this->assertStringNotContainsString('987654', $key);
        $this->assertStringNotContainsString('203.0.113.45', $key);
        $this->assertMatchesRegularExpression('/^user:[a-f0-9]{64}$/', $key);
    }

    public function test_livewire_budget_is_scoped_to_a_known_component_action(): void
    {
        $keys = app(RequestRateLimitKey::class);
        $rating = $this->livewireRequest('catalog-title-player', 'setRating');
        $watchlist = $this->livewireRequest('catalog-title-player', 'setWatchlist');
        $rating->setUserResolver(fn (): null => null);
        $watchlist->setUserResolver(fn (): null => null);

        $this->assertNotSame($keys->livewireFeature($rating), $keys->livewireFeature($watchlist));
        $this->assertSame(
            $keys->livewireFeature($rating),
            $keys->livewireFeature($this->livewireRequest('catalog-title-player', 'setRating')),
        );
        $ratingLimits = RateLimiter::limiter('livewire-action')($rating);
        $watchlistLimits = RateLimiter::limiter('livewire-action')($watchlist);

        $this->assertSame(600, $ratingLimits[0]->maxAttempts);
        $this->assertSame($ratingLimits[0]->key, $watchlistLimits[0]->key);
        $this->assertSame(180, $ratingLimits[1]->maxAttempts);
        $this->assertNotSame($ratingLimits[1]->key, $watchlistLimits[1]->key);
    }

    public function test_unknown_livewire_input_collapses_to_one_bounded_budget(): void
    {
        $keys = app(RequestRateLimitKey::class);

        $first = $this->livewireRequest('attacker-component-one', 'arbitraryOne');
        $second = $this->livewireRequest('attacker-component-two', 'arbitraryTwo');

        $this->assertSame($keys->livewireFeature($first), $keys->livewireFeature($second));
        $this->assertSame('unknown', $keys->livewireFeature($first));
    }

    public function test_livewire_transport_does_not_register_a_duplicate_global_budget(): void
    {
        $this->assertNull(RateLimiter::limiter('livewire-global'));
    }

    private function livewireRequest(string $component, string $method): Request
    {
        return Request::create('/livewire/update', 'POST', [
            'components' => [[
                'snapshot' => json_encode(['memo' => ['name' => $component]], JSON_THROW_ON_ERROR),
                'calls' => [['method' => $method]],
            ]],
        ]);
    }
}
