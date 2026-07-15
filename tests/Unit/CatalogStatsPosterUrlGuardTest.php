<?php

namespace Tests\Unit;

use App\Services\Catalog\CatalogStatsPosterUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CatalogStatsPosterUrlGuardTest extends TestCase
{
    public function test_it_accepts_https_urls_with_a_public_literal_ip(): void
    {
        $url = 'https://93.184.216.34/poster.jpg';

        $verified = (new CatalogStatsPosterUrlGuard)->verifiedUrl($url);

        $this->assertNotNull($verified);
        $this->assertSame($url, $verified->url);
        $this->assertSame('93.184.216.34', $verified->host);
        $this->assertSame('93.184.216.34', $verified->pinnedAddress);
    }

    public function test_it_fails_closed_for_unresolvable_hosts_without_emitting_an_error(): void
    {
        $this->assertNull(
            (new CatalogStatsPosterUrlGuard)->verifiedUrl('https://poster-host-does-not-exist.invalid/poster.jpg'),
        );
    }

    #[DataProvider('unsafeUrlProvider')]
    public function test_it_rejects_unsafe_urls(string $url): void
    {
        $this->assertNull((new CatalogStatsPosterUrlGuard)->verifiedUrl($url));
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeUrlProvider(): iterable
    {
        yield 'plain HTTP' => ['http://93.184.216.34/poster.jpg'];
        yield 'private IPv4' => ['https://127.0.0.1/poster.jpg'];
        yield 'private IPv6' => ['https://[::1]/poster.jpg'];
        yield 'credentials' => ['https://user:secret@93.184.216.34/poster.jpg'];
        yield 'non-standard port' => ['https://93.184.216.34:8443/poster.jpg'];
    }
}
