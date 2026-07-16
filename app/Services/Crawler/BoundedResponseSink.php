<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\Exceptions\Crawler\RemoteResponseTooLargeException;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

final class BoundedResponseSink implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    public function __construct(private readonly int $maximumBytes)
    {
        if ($maximumBytes < 1) {
            throw new InvalidArgumentException('Лимит внешнего ответа должен быть положительным.');
        }

        $this->stream = Utils::streamFor(Utils::tryFopen('php://temp', 'w+b'));
    }

    public function write($string): int
    {
        if (! is_string($string)) {
            throw new InvalidArgumentException('В bounded response sink можно записывать только строки.');
        }

        $currentSize = $this->getSize() ?? 0;
        $nextSize = max($currentSize, $this->tell() + strlen($string));

        if ($nextSize > $this->maximumBytes) {
            throw new RemoteResponseTooLargeException($this->maximumBytes);
        }

        return $this->stream->write($string);
    }
}
