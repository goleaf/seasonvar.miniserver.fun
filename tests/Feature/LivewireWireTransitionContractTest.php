<?php

namespace Tests\Feature;

use App\Livewire\Collections\CatalogCollectionDashboard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class LivewireWireTransitionContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_create_panel_transitions_only_while_it_is_rendered(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(CatalogCollectionDashboard::class)
            ->assertDontSeeHtml('wire:transition')
            ->set('showCreate', true)
            ->assertSeeHtml('aria-expanded="true"')
            ->assertSeeHtml('wire:transition')
            ->assertSeeHtml('wire:submit="create"')
            ->set('showCreate', false)
            ->assertDontSeeHtml('wire:transition');
    }
}
