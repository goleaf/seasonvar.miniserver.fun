<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\Contracts\Premium\PremiumPaymentGateway;
use InvalidArgumentException;

final class PremiumPaymentGatewayRegistry
{
    /** @var array<string, PremiumPaymentGateway> */
    private array $gateways = [];

    /** @param iterable<PremiumPaymentGateway> $gateways */
    public function __construct(iterable $gateways = [])
    {
        foreach ($gateways as $gateway) {
            $code = $gateway->code();

            if (preg_match('/\A[a-z0-9][a-z0-9_-]{1,31}\z/', $code) !== 1
                || preg_match('/\A[a-z0-9][a-z0-9_-]{1,23}\z/', $gateway->environment()) !== 1
                || isset($this->gateways[$code])) {
                throw new InvalidArgumentException('Некорректная или повторяющаяся identity платёжного провайдера.');
            }

            $this->gateways[$code] = $gateway;
        }
    }

    public function get(string $code): ?PremiumPaymentGateway
    {
        return $this->gateways[$code] ?? null;
    }

    public function available(string $code, ?string $capability = null): bool
    {
        $gateway = $this->get($code);

        return $gateway !== null && ($capability === null || $gateway->supports($capability));
    }

    public function supportsHostedRedirects(string $code): bool
    {
        return $this->available($code, 'hosted_checkout') && $this->checkoutHosts($code) !== [];
    }

    public function hostedCheckoutAvailable(): bool
    {
        return collect($this->codes())->contains(fn (string $code): bool => $this->supportsHostedRedirects($code));
    }

    public function allowsHostedRedirect(string $code, string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }

        return in_array(mb_strtolower($parts['host']), $this->checkoutHosts($code), true);
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->gateways);
    }

    /** @return list<string> */
    private function checkoutHosts(string $code): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $host): string => is_string($host) ? mb_strtolower(trim($host)) : '',
            (array) config("premium.providers.{$code}.checkout_hosts", []),
        ), static fn (string $host): bool => filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false)));
    }
}
