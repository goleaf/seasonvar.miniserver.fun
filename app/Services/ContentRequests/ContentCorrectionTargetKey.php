<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use App\Enums\ContentCorrectionField;

final class ContentCorrectionTargetKey
{
    public function make(ContentCorrectionField $field, ?int $targetId = null): string
    {
        return $field->value.':'.($targetId ?? 'root');
    }

    /** @return array{field: ContentCorrectionField, target_id: int|null}|null */
    public function parse(?string $key): ?array
    {
        if (! is_string($key)
            || preg_match('/\A([a-z_]+):(root|[1-9][0-9]*)\z/D', $key, $matches) !== 1) {
            return null;
        }

        $field = ContentCorrectionField::tryFrom($matches[1]);

        if ($field === null) {
            return null;
        }

        return [
            'field' => $field,
            'target_id' => $matches[2] === 'root' ? null : (int) $matches[2],
        ];
    }

    public function identity(?string $key): string
    {
        $parsed = $this->parse($key);

        return $parsed === null || $parsed['target_id'] === null ? '' : $key;
    }

    public function equivalent(?string $first, ?string $second): bool
    {
        return $this->identity($first) === $this->identity($second);
    }
}
