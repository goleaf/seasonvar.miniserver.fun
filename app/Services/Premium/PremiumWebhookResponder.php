<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\Exceptions\InvalidPremiumWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class PremiumWebhookResponder
{
    public function __construct(
        private readonly PremiumPaymentGatewayRegistry $gateways,
        private readonly PremiumBillingReconciler $reconciler,
    ) {}

    public function response(Request $request, string $provider): JsonResponse
    {
        $gateway = $this->gateways->get($provider);

        if ($gateway === null) {
            return response()->json(['received' => false], 404, ['Cache-Control' => 'no-store']);
        }

        $rawBody = $request->getContent();
        $maximum = max(1024, (int) config('premium.webhook_max_bytes', 262144));

        if (strlen($rawBody) > $maximum) {
            return response()->json(['received' => false], 413, ['Cache-Control' => 'no-store']);
        }

        try {
            $event = $gateway->verifyAndParseWebhook($rawBody, $request->headers->all());

            if (! hash_equals($gateway->environment(), $event->environment)) {
                throw new InvalidPremiumWebhook('Webhook относится к другому provider environment.');
            }

            $this->reconciler->process($provider, $event, hash('sha256', $rawBody));
        } catch (InvalidPremiumWebhook) {
            return response()->json(['received' => false], 400, ['Cache-Control' => 'no-store']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['received' => false], 500, ['Cache-Control' => 'no-store']);
        }

        return response()->json(['received' => true], headers: ['Cache-Control' => 'no-store']);
    }
}
