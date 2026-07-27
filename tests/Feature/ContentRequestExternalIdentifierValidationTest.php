<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\ContentRequests\ContentRequestFormPage;
use App\Models\User;
use App\Services\ContentRequests\ContentRequestExternalIdentifierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class ContentRequestExternalIdentifierValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_rejects_a_duplicate_normalized_provider_identifier_pair(): void
    {
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]));

        Livewire::test(ContentRequestFormPage::class)
            ->set('title', 'Проверяемый сериал')
            ->set('externalIdentifiers', [
                ['provider' => 'imdb', 'identifier' => 'tt1234567'],
                ['provider' => 'IMDB', 'identifier' => 'TT1234567'],
            ])
            ->call('submit')
            ->assertHasErrors(['externalIdentifiers.1.identifier']);
    }

    public function test_form_rejects_unknown_providers_and_unexpected_nested_keys(): void
    {
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]));

        Livewire::test(ContentRequestFormPage::class)
            ->set('title', 'Проверяемый сериал')
            ->set('externalIdentifiers', [[
                'provider' => 'unknown-provider',
                'identifier' => '123',
                'trusted' => true,
            ]])
            ->call('submit')
            ->assertHasErrors([
                'externalIdentifiers.0',
                'externalIdentifiers.0.provider',
            ]);
    }

    public function test_composite_identity_allows_the_same_identifier_for_different_providers(): void
    {
        $normalized = app(ContentRequestExternalIdentifierService::class)->normalize([
            ['provider' => 'tmdb', 'identifier' => '123'],
            ['provider' => 'tvdb', 'identifier' => '123'],
        ]);

        $this->assertSame(
            ['tmdb:123', 'tvdb:123'],
            collect($normalized)
                ->map(fn (array $identifier): string => $identifier['provider'].':'.$identifier['normalized_identifier'])
                ->all(),
        );
    }
}
