<?php

namespace App\Services\Storage;

final readonly class StoredPrivateUpload
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $visibility,
        public ?string $mimeType,
        public int $size,
    ) {}
}
