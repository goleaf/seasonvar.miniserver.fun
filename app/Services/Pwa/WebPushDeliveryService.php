<?php

declare(strict_types=1);

namespace App\Services\Pwa;

use App\Models\WebPushSubscription;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class WebPushDeliveryService
{
    public function __construct(
        private readonly VapidTokenFactory $tokens,
        private readonly WebPushEndpointGuard $endpoints,
        private readonly WebPushSubscriptionService $subscriptions,
    ) {}

    public function deliver(int $subscriptionId): void
    {
        if (! $this->subscriptions->configured()) {
            return;
        }

        $subscription = WebPushSubscription::query()
            ->whereNull('disabled_at')
            ->find($subscriptionId);

        if (! $subscription instanceof WebPushSubscription) {
            return;
        }

        try {
            $endpoint = $subscription->endpoint;

            if (! $this->endpoints->allows($endpoint)) {
                $this->disable($subscription);

                return;
            }

            $response = Http::withHeaders([
                'Authorization' => $this->tokens->authorization($endpoint),
                'Content-Length' => '0',
                'TTL' => '300',
                'Urgency' => 'normal',
            ])
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(max(1, (int) config('pwa.push.connect_timeout_seconds', 3)))
                ->timeout(max(2, (int) config('pwa.push.timeout_seconds', 8)))
                ->retry(
                    max(1, (int) config('pwa.push.retry_times', 2)),
                    max(0, (int) config('pwa.push.retry_sleep_milliseconds', 250)),
                    function (Throwable $exception): bool {
                        if ($exception instanceof ConnectionException) {
                            return true;
                        }

                        return $exception instanceof RequestException
                            && ($exception->response->status() === 429 || $exception->response->serverError());
                    },
                    throw: false,
                )
                ->send('POST', $endpoint, ['body' => '']);

            if (in_array($response->status(), [200, 201, 202, 204], true)) {
                $subscription->forceFill([
                    'failure_count' => 0,
                    'last_success_at' => now(),
                    'last_failure_at' => null,
                ])->save();

                return;
            }

            if (in_array($response->status(), [404, 410], true)) {
                $this->disable($subscription);

                return;
            }

            if ($response->status() === 429 || $response->serverError()) {
                $this->recordFailure($subscription);

                throw WebPushTransientDeliveryException::providerUnavailable();
            }

            $this->recordFailure($subscription);
        } catch (WebPushTransientDeliveryException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->recordFailure($subscription);
            Log::warning('Web Push не доставлен; capability оставлен без раскрытия.', [
                'subscription_id' => $subscription->id,
                'exception' => $exception::class,
            ]);

            throw WebPushTransientDeliveryException::providerUnavailable();
        }
    }

    private function recordFailure(WebPushSubscription $subscription): void
    {
        $failures = $subscription->failure_count + 1;
        $threshold = max(1, (int) config('pwa.push.failure_disable_threshold', 5));

        $subscription->forceFill([
            'failure_count' => $failures,
            'last_failure_at' => now(),
            'disabled_at' => $failures >= $threshold ? now() : null,
        ])->save();
    }

    private function disable(WebPushSubscription $subscription): void
    {
        $subscription->forceFill([
            'failure_count' => max(1, $subscription->failure_count + 1),
            'last_failure_at' => now(),
            'disabled_at' => now(),
        ])->save();
    }
}
