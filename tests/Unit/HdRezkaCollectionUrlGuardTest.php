<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Collections\Import\HdRezkaCollectionUrlGuard;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class HdRezkaCollectionUrlGuardTest extends TestCase
{
    public function test_guard_accepts_only_the_expected_path_for_each_purpose(): void
    {
        $guard = app(HdRezkaCollectionUrlGuard::class);

        $this->assertSame(
            'https://hdrezka.my/collections.html',
            $guard->absolute('/collections.html', HdRezkaCollectionUrlGuard::PURPOSE_INDEX),
        );
        $this->assertSame(
            'https://hdrezka.my/xfsearch/collections/films/page/2/',
            $guard->absolute(
                'https://hdrezka.my/xfsearch/collections/films/page/2/',
                HdRezkaCollectionUrlGuard::PURPOSE_COLLECTION,
            ),
        );
        $this->assertSame(
            'https://hdrezka.my/uploads/mini/14/aa/cover.jpg',
            $guard->absolute('/uploads/mini/14/aa/cover.jpg', HdRezkaCollectionUrlGuard::PURPOSE_COVER),
        );
        $this->assertSame(
            'https://hdrezka.my/668-mufasa-the-lion-king.html',
            $guard->absolute('/668-mufasa-the-lion-king.html', HdRezkaCollectionUrlGuard::PURPOSE_DETAIL),
        );
        $this->assertSame(
            'https://hdrezka.my/19079-пракурор.html',
            $guard->absolute('/19079-пракурор.html', HdRezkaCollectionUrlGuard::PURPOSE_DETAIL),
        );
        $this->assertSame(
            'https://hdrezka.my/24053-.html',
            $guard->absolute('/24053-.html', HdRezkaCollectionUrlGuard::PURPOSE_DETAIL),
        );
    }

    #[DataProvider('unsafeUrlProvider')]
    public function test_guard_rejects_unsafe_or_cross_purpose_urls(string $url, string $purpose): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(HdRezkaCollectionUrlGuard::class)->absolute($url, $purpose);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsafeUrlProvider(): iterable
    {
        yield 'off-host' => ['https://example.test/collections.html', HdRezkaCollectionUrlGuard::PURPOSE_INDEX];
        yield 'http scheme' => ['http://hdrezka.my/collections.html', HdRezkaCollectionUrlGuard::PURPOSE_INDEX];
        yield 'non-standard port' => ['https://hdrezka.my:444/collections.html', HdRezkaCollectionUrlGuard::PURPOSE_INDEX];
        yield 'userinfo' => ['https://user@hdrezka.my/collections.html', HdRezkaCollectionUrlGuard::PURPOSE_INDEX];
        yield 'query string' => ['https://hdrezka.my/collections.html?token=secret', HdRezkaCollectionUrlGuard::PURPOSE_INDEX];
        yield 'fragment' => ['https://hdrezka.my/collections.html#part', HdRezkaCollectionUrlGuard::PURPOSE_INDEX];
        yield 'protocol-relative' => ['//example.test/collections.html', HdRezkaCollectionUrlGuard::PURPOSE_INDEX];
        yield 'encoded traversal' => ['/uploads/mini/%2e%2e/cover.jpg', HdRezkaCollectionUrlGuard::PURPOSE_COVER];
        yield 'encoded nul' => ['/xfsearch/collections/films%00/', HdRezkaCollectionUrlGuard::PURPOSE_COLLECTION];
        yield 'double encoded nul' => ['/xfsearch/collections/films%2500/', HdRezkaCollectionUrlGuard::PURPOSE_COLLECTION];
        yield 'double encoded traversal' => ['/xfsearch/collections/%252e%252e/', HdRezkaCollectionUrlGuard::PURPOSE_COLLECTION];
        yield 'encoded slash' => ['/xfsearch/collections/films%2Fpage%2F2/', HdRezkaCollectionUrlGuard::PURPOSE_COLLECTION];
        yield 'double encoded slash' => ['/xfsearch/collections/films%252Fpage%252F2/', HdRezkaCollectionUrlGuard::PURPOSE_COLLECTION];
        yield 'residual percent' => ['/xfsearch/collections/films%25/', HdRezkaCollectionUrlGuard::PURPOSE_COLLECTION];
        yield 'unsupported collection slug punctuation' => ['/xfsearch/collections/films|series/', HdRezkaCollectionUrlGuard::PURPOSE_COLLECTION];
        yield 'collection used as detail' => ['/xfsearch/collections/films/', HdRezkaCollectionUrlGuard::PURPOSE_DETAIL];
        yield 'cover used as collection' => ['/uploads/mini/14/aa/cover.jpg', HdRezkaCollectionUrlGuard::PURPOSE_COLLECTION];
        yield 'unknown purpose' => ['/collections.html', 'other'];
    }
}
