<?php

declare(strict_types=1);

namespace Tests\Feature\Premium;

use App\Contracts\Premium\PremiumPaymentGateway;
use App\DTOs\Premium\PremiumHostedCheckout;
use App\Enums\AdminMembershipStatus;
use App\Enums\AdminRoleCode;
use App\Enums\PremiumCheckoutStatus;
use App\Enums\PremiumEntitlementSource;
use App\Enums\PremiumFeature;
use App\Enums\PremiumPlanType;
use App\Livewire\Premium\PremiumAdministrationManager;
use App\Livewire\Premium\PremiumPricingPage;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use App\Models\PremiumCheckoutSession;
use App\Models\PremiumEntitlement;
use App\Models\PremiumPlan;
use App\Models\User;
use App\Services\Premium\PremiumPaymentGatewayRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\LivewireManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class PremiumAuthorizationAndLivewireStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'premium.providers.testpay.checkout_hosts' => ['checkout.test'],
            'premium.supported_currencies' => ['USD'],
            'premium.server_region_code' => 'LT',
            'premium.rate_limits.administration_per_minute' => 30,
            'premium.rate_limits.checkout_per_minute' => 4,
        ]);

        $this->installGateway();
    }

    #[Test]
    public function verified_route_middleware_is_persistent_for_livewire_updates(): void
    {
        self::assertContains(
            EnsureEmailIsVerified::class,
            Livewire::getPersistentMiddleware(),
        );
    }

    #[Test]
    public function premium_routes_and_pricing_controls_do_not_mutate_through_get_requests(): void
    {
        $this->createPlan();

        foreach ([
            'premium.index',
            'localized.premium.index',
            'premium.return',
            'localized.premium.return',
            'admin.premium',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            self::assertNotNull($route, $routeName);
            self::assertSame(['GET', 'HEAD'], $route->methods(), $routeName);
            self::assertDoesNotMatchRegularExpression(
                '/(?:grant|revoke|coupon|checkout\/create|purchase)/i',
                $route->uri(),
                $routeName,
            );
        }

        $webhook = Route::getRoutes()->getByName('premium.webhook');

        self::assertNotNull($webhook);
        self::assertSame(['POST'], $webhook->methods());

        $this->get(route('premium.index'))
            ->assertOk()
            ->assertSee('wire:submit="startCheckout"', false)
            ->assertSee('wire:loading.attr="disabled"', false)
            ->assertSee('wire:target="startCheckout"', false);
    }

    #[Test]
    public function guest_with_a_valid_plan_is_redirected_to_the_localized_login_without_creating_checkout(): void
    {
        $plan = $this->createPlan();

        $this->livewire(PremiumPricingPage::class, ['locale' => 'en'])
            ->set('selectedPlan', $plan->code)
            ->call('startCheckout')
            ->assertRedirect(route('localized.login', ['locale' => 'en']));

        self::assertSame(
            route('localized.premium.index', ['locale' => 'en', 'plan' => $plan->code]),
            session('url.intended'),
        );
        self::assertSame(0, PremiumCheckoutSession::query()->count());
    }

    #[Test]
    public function unverified_account_cannot_create_checkout(): void
    {
        $plan = $this->createPlan();
        $user = User::factory()->unverified()->create();

        $this->livewireAs($user, PremiumPricingPage::class)
            ->set('selectedPlan', $plan->code)
            ->call('startCheckout')
            ->assertHasErrors([
                'selectedPlan' => __('premium.errors.verified_account_required'),
            ]);

        self::assertSame(0, PremiumCheckoutSession::query()->count());
    }

    #[Test]
    public function invalid_plan_input_fails_before_checkout_or_gateway_call(): void
    {
        $gateway = $this->installGateway();
        $gateway->expects(self::never())->method('createHostedCheckout');
        $user = User::factory()->create();

        $this->livewireAs($user, PremiumPricingPage::class)
            ->set('selectedPlan', str_repeat('x', 65))
            ->call('startCheckout')
            ->assertHasErrors([
                'selectedPlan' => __('premium.errors.plan_unavailable'),
            ]);

        self::assertSame(0, PremiumCheckoutSession::query()->count());
    }

    #[Test]
    public function locked_checkout_token_rejects_client_tampering(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->livewire(PremiumPricingPage::class)
            ->set('checkoutToken', (string) Str::uuid());
    }

    #[Test]
    public function verified_account_uses_server_checkout_state_and_allowlisted_redirect(): void
    {
        $plan = $this->createPlan();
        $user = User::factory()->create();
        $redirectUrl = 'https://checkout.test/session/task-6';
        $this->installGateway(new PremiumHostedCheckout(
            providerSessionId: 'session-task-6',
            redirectUrl: $redirectUrl,
            expiresAt: CarbonImmutable::now()->addMinutes(10),
        ));

        $this->livewireAs($user, PremiumPricingPage::class)
            ->set('selectedPlan', $plan->code)
            ->call('startCheckout')
            ->assertHasNoErrors()
            ->assertRedirect($redirectUrl);

        $checkout = PremiumCheckoutSession::query()->sole();

        self::assertSame($user->id, $checkout->user_id);
        self::assertSame($plan->id, $checkout->premium_plan_id);
        self::assertSame(PremiumCheckoutStatus::Pending, $checkout->status);
        self::assertSame('testpay', $checkout->provider_code);
        self::assertSame(12345, $checkout->amount_minor);
        self::assertSame('USD', $checkout->currency);
    }

    #[Test]
    public function ordinary_user_cannot_mount_premium_administration(): void
    {
        $this->livewireAs(User::factory()->create(), PremiumAdministrationManager::class)
            ->assertForbidden();
    }

    #[Test]
    public function view_only_administrator_cannot_call_grant_or_promotion_actions(): void
    {
        $administrator = User::factory()->create();
        $this->assignRole($administrator, AdminRoleCode::PortalAdministrator);

        $this->livewireAs($administrator, PremiumAdministrationManager::class)
            ->set('userSearch', User::factory()->create()->email)
            ->call('findUser')
            ->assertForbidden();

        $this->livewireAs($administrator, PremiumAdministrationManager::class)
            ->call('createPromotion')
            ->assertForbidden();
    }

    #[Test]
    public function selected_user_public_id_is_locked_against_client_tampering(): void
    {
        $administrator = User::factory()->create();
        $selected = User::factory()->create();
        $other = User::factory()->create();
        $this->assignRole($administrator, AdminRoleCode::PremiumManager);
        $component = $this->livewireAs($administrator, PremiumAdministrationManager::class)
            ->set('userSearch', $selected->email)
            ->call('findUser')
            ->assertSet('selectedUserPublicId', $selected->public_id);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        $component->set('selectedUserPublicId', $other->public_id);
    }

    #[Test]
    public function revoke_cannot_cross_the_selected_user_boundary(): void
    {
        $administrator = User::factory()->create();
        $selected = User::factory()->create();
        $other = User::factory()->create();
        $this->assignRole($administrator, AdminRoleCode::PremiumManager);
        $selectedEntitlement = $this->createEntitlement($selected, 'selected');
        $otherEntitlement = $this->createEntitlement($other, 'other');

        $exception = null;

        try {
            $this->livewireAs($administrator, PremiumAdministrationManager::class)
                ->set('userSearch', $selected->email)
                ->call('findUser')
                ->call('revoke', $otherEntitlement->public_id);
        } catch (ModelNotFoundException $caught) {
            $exception = $caught;
        }

        self::assertInstanceOf(ModelNotFoundException::class, $exception);
        self::assertNull($selectedEntitlement->fresh()->revoked_at);
        self::assertNull($otherEntitlement->fresh()->revoked_at);
    }

    #[Test]
    public function invalid_administration_action_uuids_fail_without_mutation(): void
    {
        $administrator = User::factory()->create();
        $this->assignRole($administrator, AdminRoleCode::PremiumManager);

        $this->livewireAs($administrator, PremiumAdministrationManager::class)
            ->call('revoke', 'not-a-uuid')
            ->assertNotFound();

        $this->livewireAs($administrator, PremiumAdministrationManager::class)
            ->call('createCoupon', 'not-a-uuid')
            ->assertNotFound();

        self::assertSame(0, PremiumEntitlement::query()->whereNotNull('revoked_at')->count());
    }

    #[Test]
    public function administration_inputs_are_validated_before_mutation(): void
    {
        $administrator = User::factory()->create();
        $this->assignRole($administrator, AdminRoleCode::PremiumManager);

        $this->livewireAs($administrator, PremiumAdministrationManager::class)
            ->set('userSearch', '')
            ->call('findUser')
            ->assertHasErrors('userSearch')
            ->set('userSearch', str_repeat('x', 192))
            ->call('findUser')
            ->assertHasErrors('userSearch');

        $this->livewireAs($administrator, PremiumAdministrationManager::class)
            ->set('durationDays', 0)
            ->set('reason', 'not-a-reason')
            ->set('privateNote', str_repeat('x', 1001))
            ->call('grant')
            ->assertHasErrors(['durationDays', 'reason', 'privateNote']);

        $this->livewireAs($administrator, PremiumAdministrationManager::class)
            ->set('promotionCode', '!')
            ->set('promotionDurationDays', 0)
            ->set('promotionStartsAt', '2026-07-26 12:00')
            ->set('promotionEndsAt', '2026-07-25 12:00')
            ->set('promotionTotalLimit', '0')
            ->set('promotionPerUserLimit', 21)
            ->call('createPromotion')
            ->assertHasErrors([
                'promotionCode',
                'promotionDurationDays',
                'promotionEndsAt',
                'promotionTotalLimit',
                'promotionPerUserLimit',
            ]);

        self::assertSame(0, PremiumEntitlement::query()->count());
    }

    #[Test]
    public function administration_rate_limit_is_user_scoped_and_fails_closed(): void
    {
        config(['premium.rate_limits.administration_per_minute' => 1]);
        $administrator = User::factory()->create();
        $selected = User::factory()->create();
        $this->assignRole($administrator, AdminRoleCode::PremiumManager);
        $key = 'premium-administration:user:'.$administrator->id;

        $this->livewireAs($administrator, PremiumAdministrationManager::class)
            ->set('userSearch', $selected->email)
            ->call('findUser')
            ->assertHasNoErrors()
            ->set('userSearch', $selected->email)
            ->call('findUser')
            ->assertHasErrors([
                'userSearch' => __('premium.errors.administration_rate_limited'),
            ]);

        self::assertSame(1, RateLimiter::attempts($key));
        self::assertSame(0, RateLimiter::attempts('premium-administration:user:'.$administrator->email));
    }

    #[Test]
    public function checkout_rate_limit_prevents_a_second_gateway_call(): void
    {
        config(['premium.rate_limits.checkout_per_minute' => 1]);
        $plan = $this->createPlan();
        $user = User::factory()->create();
        $redirectUrl = 'https://checkout.test/session/rate-limit';
        $this->installGateway(new PremiumHostedCheckout(
            providerSessionId: 'session-rate-limit',
            redirectUrl: $redirectUrl,
            expiresAt: CarbonImmutable::now()->addMinutes(10),
        ));

        $this->livewireAs($user, PremiumPricingPage::class)
            ->set('selectedPlan', $plan->code)
            ->call('startCheckout')
            ->assertRedirect($redirectUrl)
            ->call('startCheckout')
            ->assertHasErrors([
                'selectedPlan' => __('premium.errors.checkout_failed'),
            ]);

        self::assertSame(1, PremiumCheckoutSession::query()->count());
        self::assertSame(1, RateLimiter::attempts('premium-checkout:user:'.$user->id));
    }

    private function createPlan(): PremiumPlan
    {
        $code = 'task-6-secure-plan';
        Lang::addLines([
            "premium.plans.{$code}.name" => 'Безопасный тестовый тариф',
            "premium.plans.{$code}.description" => 'Тестовая запись без production-активации.',
        ], 'ru');
        Lang::addLines([
            "premium.plans.{$code}.name" => 'Secure test plan',
            "premium.plans.{$code}.description" => 'A test-only record without production activation.',
        ], 'en');

        return PremiumPlan::query()->create([
            'code' => $code,
            'type' => PremiumPlanType::OneTimeDuration,
            'duration_days' => 30,
            'billing_interval' => null,
            'amount_minor' => 12345,
            'currency' => 'USD',
            'entitlement_codes' => [PremiumFeature::PremiumAccess->value],
            'provider_code' => 'testpay',
            'provider_product_id' => 'product-task-6',
            'provider_price_id' => 'price-task-6',
            'region_codes' => null,
            'is_active' => true,
            'is_public' => true,
            'is_legacy' => false,
            'display_order' => 1,
        ]);
    }

    /** @return PremiumPaymentGateway&MockObject */
    private function installGateway(?PremiumHostedCheckout $hosted = null): PremiumPaymentGateway
    {
        $gateway = $this->createMock(PremiumPaymentGateway::class);
        $gateway->method('code')->willReturn('testpay');
        $gateway->method('environment')->willReturn('testing');
        $gateway->method('supports')->willReturnCallback(
            static fn (string $capability): bool => in_array($capability, [
                'hosted_checkout',
                'one_time_checkout',
            ], true),
        );

        if ($hosted !== null) {
            $gateway->expects(self::once())
                ->method('createHostedCheckout')
                ->willReturn($hosted);
        }

        $this->app->instance(
            PremiumPaymentGatewayRegistry::class,
            new PremiumPaymentGatewayRegistry([$gateway]),
        );

        return $gateway;
    }

    private function assignRole(User $user, AdminRoleCode $roleCode): void
    {
        AdminUserRole::query()->create([
            'user_id' => $user->id,
            'admin_role_id' => AdminRole::query()->where('code', $roleCode)->valueOrFail('id'),
            'status' => AdminMembershipStatus::Active,
            'reason_code' => 'premium_task_6_test',
            'assigned_at' => CarbonImmutable::now(),
        ]);
    }

    private function createEntitlement(User $user, string $reference): PremiumEntitlement
    {
        return PremiumEntitlement::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'feature_code' => PremiumFeature::PremiumAccess,
            'source' => PremiumEntitlementSource::ManualGrant,
            'source_reference' => 'task-6-'.$reference,
            'application_key' => hash('sha256', 'task-6-'.$reference),
            'reason_code' => 'premium_task_6_test',
            'starts_at' => CarbonImmutable::now()->subMinute(),
            'ends_at' => CarbonImmutable::now()->addDay(),
            'is_lifetime' => false,
        ]);
    }

    /**
     * @template TComponent of Component
     *
     * @param  class-string<TComponent>  $component
     * @param  array<string, mixed>  $parameters
     * @return Testable<TComponent>
     */
    private function livewire(string $component, array $parameters = []): Testable
    {
        return app(LivewireManager::class)->test(new $component, $parameters);
    }

    /**
     * @template TComponent of Component
     *
     * @param  class-string<TComponent>  $component
     * @return Testable<TComponent>
     */
    private function livewireAs(User $user, string $component): Testable
    {
        $this->actingAs($user);

        return $this->livewire($component);
    }
}
