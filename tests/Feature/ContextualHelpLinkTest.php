<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\HelpCenter\ContextualHelpLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class ContextualHelpLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_contextual_help_is_resolved_after_lazy_loading(): void
    {
        Livewire::withoutLazyLoading();

        Livewire::test(ContextualHelpLink::class, [
            'feature' => 'player',
            'context' => 'controls',
        ])
            ->assertSee('Полный экран, автозапуск и управление плеером')
            ->assertSee('/help/articles/polnyy-ekran-avtozapusk-i-upravlenie', false);
    }
}
