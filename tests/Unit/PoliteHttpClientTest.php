<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\VerifiedExternalUrlData;
use App\Exceptions\Crawler\RemoteResponseTooLargeException;
use App\Services\Crawler\PoliteHttpClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PoliteHttpClientTest extends TestCase
{
    public function test_it_rejects_a_response_body_larger_than_the_explicit_limit(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'seasonvar.ru/large.html' => Http::response('123456789', 200),
        ]);

        $this->expectException(RemoteResponseTooLargeException::class);

        app(PoliteHttpClient::class)->get(
            'https://seasonvar.ru/large.html',
            delaySeconds: 0,
            maxResponseBytes: 8,
        );
    }

    public function test_it_returns_a_response_at_the_limit_without_following_redirects(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'seasonvar.ru/exact.html' => Http::response('12345678', 200),
            'seasonvar.ru/redirect.html' => Http::response('', 302, [
                'Location' => 'http://127.0.0.1/internal',
            ]),
        ]);
        $client = app(PoliteHttpClient::class);

        $exact = $client->get(
            'https://seasonvar.ru/exact.html',
            delaySeconds: 0,
            maxResponseBytes: 8,
        );
        $redirect = $client->get(
            'https://seasonvar.ru/redirect.html',
            delaySeconds: 0,
            maxResponseBytes: 8,
        );

        $this->assertSame('12345678', $exact->body());
        $this->assertSame(302, $redirect->status());
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'http://127.0.0.1/internal');
    }

    public function test_it_accepts_a_preverified_target_without_losing_the_bounded_response_contract(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'playlist.example.com/list.m3u' => Http::response('playlist', 200),
        ]);
        $target = new VerifiedExternalUrlData(
            url: 'https://playlist.example.com/list.m3u',
            host: 'playlist.example.com',
            pinnedAddress: '203.0.113.10',
        );

        $response = app(PoliteHttpClient::class)->getVerified(
            $target,
            delaySeconds: 0,
            maxResponseBytes: 8,
        );

        $this->assertSame('playlist', $response->body());
        Http::assertSentCount(1);
    }

    public function test_it_can_read_a_reused_seekable_fake_response_without_detaching_its_stream(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'seasonvar.ru/reused.html' => Http::response('reusable', 200),
        ]);
        $client = app(PoliteHttpClient::class);

        $first = $client->get(
            'https://seasonvar.ru/reused.html',
            delaySeconds: 0,
            maxResponseBytes: 8,
        );
        $second = $client->get(
            'https://seasonvar.ru/reused.html',
            delaySeconds: 0,
            maxResponseBytes: 8,
        );

        $this->assertSame('reusable', $first->body());
        $this->assertSame('reusable', $second->body());
        Http::assertSentCount(2);
    }
}
