<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ApiSyncMutationResult
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $mutationId,
        public string $status,
        public ?int $resourceVersion = null,
        public array $data = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mutation_id' => $this->mutationId,
            'status' => $this->status,
            'resource_version' => $this->resourceVersion,
            'data' => $this->data,
        ];
    }

    /** @return array{status: string, resource_version: int|null, data: array<string, mixed>} */
    public function receipt(): array
    {
        return [
            'status' => $this->status,
            'resource_version' => $this->resourceVersion,
            'data' => $this->data,
        ];
    }
}
