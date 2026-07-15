<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PublicPageSessionCacheSafetyTest extends TestCase
{
    public function test_session_state_added_while_rendering_prevents_shared_caching(): void
    {
        config([
            'cache-architecture.stores.hot' => 'array',
            'cache-architecture.stores.domain' => 'array',
            'cache-architecture.stores.locks' => 'array',
            'cache-architecture.stores.versions' => 'array',
            'cache-architecture.page_cache.enabled' => true,
        ]);
        Route::middleware(['web', 'public.page:stats'])
            ->get('/_tests/public-page-with-rendered-session-state', function () {
                session()->flash('status', 'private-render-state');

                return response('<html><body>Приватное состояние рендера</body></html>');
            })
            ->name('tests.public-page-with-rendered-session-state');

        $this->get('/_tests/public-page-with-rendered-session-state')
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'BYPASS');
    }
}
