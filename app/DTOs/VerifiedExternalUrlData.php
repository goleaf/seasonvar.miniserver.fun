<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class VerifiedExternalUrlData
{
    public function __construct(
        public string $url,
        public string $host,
        public ?string $pinnedAddress,
        public int $port = 443,
    ) {}

    /** @return array<string, mixed> */
    public function httpOptions(): array
    {
        if ($this->pinnedAddress === null || ! defined('CURLOPT_RESOLVE')) {
            return ['stream' => true];
        }

        $address = str_contains($this->pinnedAddress, ':')
            ? '['.$this->pinnedAddress.']'
            : $this->pinnedAddress;

        return [
            'stream' => true,
            'curl' => [CURLOPT_RESOLVE => ["{$this->host}:{$this->port}:{$address}"]],
        ];
    }
}
