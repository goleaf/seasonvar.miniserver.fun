<?php

declare(strict_types=1);

namespace Tests\Feature\Premium;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PremiumPricingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_russian_pricing_page_fails_closed_without_commerce_configuration(): void
    {
        config([
            'premium.providers' => [],
            'premium.supported_currencies' => [],
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->get(route('premium.index'));
        $premiumQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(static fn (string $query): bool => str_contains($query, 'premium_'))
            ->values()
            ->all();
        DB::disableQueryLog();

        self::assertSame([], $premiumQueries);

        $response
            ->assertOk()
            ->assertSeeText('Покупка Premium сейчас недоступна')
            ->assertSeeText('Портал не показывает вымышленные цены или способы оплаты.')
            ->assertSeeText('Каталог, бесплатные источники и обычные функции аккаунта продолжают работать без Premium.')
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertSee('<link rel="canonical" href="'.route('premium.index').'">', false)
            ->assertDontSee('wire:submit="startCheckout"', false)
            ->assertDontSeeText('Выбрать тариф');
    }

    public function test_english_pricing_page_preserves_localized_unavailable_contract(): void
    {
        config([
            'premium.providers' => [],
            'premium.supported_currencies' => [],
        ]);
        $url = route('localized.premium.index', ['locale' => 'en']);

        $this->get($url)
            ->assertOk()
            ->assertSeeText('Premium purchase is currently unavailable')
            ->assertSeeText('The portal does not show invented prices or payment methods.')
            ->assertSeeText('The catalog, free sources, and regular account features remain available without Premium.')
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertSee('<link rel="canonical" href="'.$url.'">', false)
            ->assertDontSee('wire:submit="startCheckout"', false)
            ->assertDontSeeText('Choose plan');
    }

    public function test_invalid_plan_query_is_not_reflected_or_exposed_as_checkout(): void
    {
        config([
            'premium.providers' => [],
            'premium.supported_currencies' => [],
        ]);
        $invalidPlan = '<script>alert("premium")</script>';

        $this->get(route('premium.index', ['plan' => $invalidPlan]))
            ->assertOk()
            ->assertDontSee($invalidPlan, false)
            ->assertDontSee('wire:submit="startCheckout"', false)
            ->assertSeeText('Покупка Premium сейчас недоступна');
    }
}
