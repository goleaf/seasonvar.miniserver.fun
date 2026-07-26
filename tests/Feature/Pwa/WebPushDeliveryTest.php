<?php

declare(strict_types=1);

namespace Tests\Feature\Pwa;

use App\Jobs\DeliverWebPushNotification;
use App\Jobs\FanOutWebPushNotification;
use App\Listeners\QueueWebPushForDatabaseNotification;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\Pwa\WebPushDeliveryService;
use App\Services\Pwa\WebPushTransientDeliveryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WebPushDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_posts_no_payload_and_marks_an_accepted_subscription_healthy(): void
    {
        $this->configureVapid();
        $subscription = $this->subscription('accepted');

        Http::fake(fn ($request) => Http::response('', 201));

        app(WebPushDeliveryService::class)->deliver($subscription->id);

        Http::assertSent(function ($request) use ($subscription): bool {
            $authorization = (string) $request->header('Authorization')[0];

            return $request->method() === 'POST'
                && $request->url() === $subscription->endpoint
                && $request->body() === ''
                && str_starts_with($authorization, 'vapid t=')
                && str_contains($authorization, ', k=');
        });
        $this->assertNotNull($subscription->fresh()?->last_success_at);
        $this->assertSame(0, $subscription->fresh()?->failure_count);
        $this->assertNull($subscription->fresh()?->disabled_at);
    }

    public function test_gone_capability_is_disabled_without_leaking_it_into_a_job_payload(): void
    {
        $this->configureVapid();
        $subscription = $this->subscription('gone');

        Http::fake([$subscription->endpoint => Http::response('', 410)]);
        app(WebPushDeliveryService::class)->deliver($subscription->id);

        $this->assertNotNull($subscription->fresh()?->disabled_at);
        $this->assertStringNotContainsString(
            $subscription->endpoint,
            serialize(new DeliverWebPushNotification($subscription->id)),
        );
    }

    public function test_transient_provider_failure_is_retried_by_the_queue_without_leaking_the_endpoint(): void
    {
        $this->configureVapid();
        $subscription = $this->subscription('transient-private-capability');

        Http::fake([$subscription->endpoint => Http::response('', 503)]);

        try {
            app(WebPushDeliveryService::class)->deliver($subscription->id);
            $this->fail('Transient provider failure must be handed back to the queue.');
        } catch (WebPushTransientDeliveryException $exception) {
            $this->assertStringNotContainsString($subscription->endpoint, $exception->getMessage());
            $this->assertStringNotContainsString($subscription->endpoint, serialize($exception));
        }

        $this->assertSame(1, $subscription->fresh()?->failure_count);
        $this->assertNull($subscription->fresh()?->disabled_at);
    }

    public function test_permanent_provider_rejection_is_not_retried_or_marked_successful(): void
    {
        $this->configureVapid();
        config(['pwa.push.retry_times' => 3]);
        $subscription = $this->subscription('permanent-rejection');

        Http::fakeSequence()
            ->push('', 400)
            ->push('', 201);

        app(WebPushDeliveryService::class)->deliver($subscription->id);

        Http::assertSentCount(1);
        $this->assertSame(1, $subscription->fresh()?->failure_count);
        $this->assertNull($subscription->fresh()?->last_success_at);
        $this->assertNull($subscription->fresh()?->disabled_at);
    }

    public function test_database_notification_dispatches_only_an_owner_id_fanout_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $event = new NotificationSent(
            $user,
            new PwaDatabaseProbeNotification,
            'database',
        );

        app(QueueWebPushForDatabaseNotification::class)->handle($event);

        Queue::assertPushed(
            FanOutWebPushNotification::class,
            fn (FanOutWebPushNotification $job): bool => $job->userId === $user->id,
        );
        Queue::assertNotPushed(DeliverWebPushNotification::class);
    }

    private function subscription(string $suffix): WebPushSubscription
    {
        $user = User::factory()->create();
        $endpoint = "https://fcm.googleapis.com/fcm/send/{$suffix}";

        return WebPushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'installation_hash' => hash('sha256', (string) Str::uuid()),
            'locale' => 'ru',
        ]);
    }

    private function configureVapid(): void
    {
        $resource = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $this->assertNotFalse($resource);
        $this->assertTrue(openssl_pkey_export($resource, $privateKey));
        $details = openssl_pkey_get_details($resource);
        $this->assertIsArray($details);
        $this->assertIsArray($details['ec'] ?? null);
        $publicKey = "\x04".$details['ec']['x'].$details['ec']['y'];

        config([
            'pwa.enabled' => true,
            'pwa.push.enabled' => true,
            'pwa.push.private_key' => base64_encode($privateKey),
            'pwa.push.public_key' => rtrim(strtr(base64_encode($publicKey), '+/', '-_'), '='),
            'pwa.push.subject' => 'mailto:operations@example.com',
            'pwa.push.retry_times' => 1,
        ]);
    }
}

final class PwaDatabaseProbeNotification extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }
}
