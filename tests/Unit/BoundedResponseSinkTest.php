<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\Crawler\RemoteResponseTooLargeException;
use App\Services\Crawler\BoundedResponseSink;
use PHPUnit\Framework\TestCase;

final class BoundedResponseSinkTest extends TestCase
{
    public function test_it_never_writes_beyond_the_configured_response_limit(): void
    {
        $sink = new BoundedResponseSink(8);

        $this->assertSame(8, $sink->write('12345678'));
        $this->assertSame(8, $sink->getSize());

        $this->expectException(RemoteResponseTooLargeException::class);

        $sink->write('9');
    }
}
