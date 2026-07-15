<?php

declare(strict_types=1);

namespace App\DTOs\Operations;

final readonly class DeploymentCheck
{
    /**
     * @param  array<string, bool|int|string|null>  $metadata
     */
    public function __construct(
        public string $name,
        public string $status,
        public string $message,
        public array $metadata = [],
        public int $durationMs = 0,
    ) {}

    public function failed(): bool
    {
        return $this->status === 'fail';
    }

    public function withDuration(int $durationMs): self
    {
        return new self(
            name: $this->name,
            status: $this->status,
            message: $this->message,
            metadata: $this->metadata,
            durationMs: max(0, $durationMs),
        );
    }

    /**
     * @return array{name: string, status: string, message: string, metadata: array<string, bool|int|string|null>, duration_ms: int}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status,
            'message' => $this->message,
            'metadata' => $this->metadata,
            'duration_ms' => $this->durationMs,
        ];
    }
}
