<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Jobs\FinalizeSeasonvarImportTitleGroup;
use App\Jobs\FinalizeSeasonvarQueuedImport;
use JsonException;

final class FailedFinalizerPayloadInspector
{
    public const MAX_PAYLOAD_BYTES = 16_384;

    private const MAX_COMMAND_BYTES = 8_192;

    private const MAX_SERIALIZED_DEPTH = 8;

    private const MAX_SERIALIZED_ITEMS = 1_024;

    private const TARGETS = [
        FinalizeSeasonvarImportTitleGroup::class => [
            'type' => 'title_group',
            'property' => 'groupId',
        ],
        FinalizeSeasonvarQueuedImport::class => [
            'type' => 'global_run',
            'property' => 'importRunId',
        ],
    ];

    /** @return array{type: 'title_group'|'global_run', target_id: int}|null */
    public function inspect(string $payload): ?array
    {
        if ($payload === '' || strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || ! is_array($decoded['data'] ?? null)) {
            return null;
        }

        $displayName = $decoded['displayName'] ?? null;
        $commandName = $decoded['data']['commandName'] ?? null;
        $command = $decoded['data']['command'] ?? null;

        if (! is_string($displayName)
            || ! isset(self::TARGETS[$displayName])
            || $commandName !== $displayName
            || ! is_string($command)
            || strlen($command) > self::MAX_COMMAND_BYTES
        ) {
            return null;
        }

        $definition = self::TARGETS[$displayName];
        $targetId = $this->directPositiveIntegerProperty(
            $command,
            $displayName,
            $definition['property'],
        );

        if ($targetId === null) {
            return null;
        }

        return [
            'type' => $definition['type'],
            'target_id' => $targetId,
        ];
    }

    private function directPositiveIntegerProperty(string $serialized, string $expectedClass, string $property): ?int
    {
        $offset = 0;
        $header = $this->readObjectHeader($serialized, $offset);

        if ($header === null || $header['class'] !== $expectedClass) {
            return null;
        }

        $target = null;

        for ($index = 0; $index < $header['items']; $index++) {
            $name = $this->readStringValue($serialized, $offset, 's');
            $value = $this->readValue($serialized, $offset, 1);

            if ($name === null || $value === null) {
                return null;
            }

            if ($name['value'] === $property && $value['type'] === 'i') {
                $validated = filter_var($value['value'], FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
                ]);

                if (! is_int($validated) || $target !== null) {
                    return null;
                }

                $target = $validated;
            }
        }

        if (! $this->consume($serialized, $offset, '}') || $offset !== strlen($serialized)) {
            return null;
        }

        return $target;
    }

    /** @return array{class: string, items: int}|null */
    private function readObjectHeader(string $serialized, int &$offset): ?array
    {
        if (! $this->consume($serialized, $offset, 'O:')) {
            return null;
        }

        $classLength = $this->readUnsignedInteger($serialized, $offset, ':');

        if ($classLength === null || ! $this->consume($serialized, $offset, '"')) {
            return null;
        }

        $class = $this->readBytes($serialized, $offset, $classLength);

        if ($class === null || ! $this->consume($serialized, $offset, '":')) {
            return null;
        }

        $items = $this->readUnsignedInteger($serialized, $offset, ':');

        if ($items === null
            || $items > self::MAX_SERIALIZED_ITEMS
            || ! $this->consume($serialized, $offset, '{')
        ) {
            return null;
        }

        return ['class' => $class, 'items' => $items];
    }

    /** @return array{type: string, value: string}|null */
    private function readValue(string $serialized, int &$offset, int $depth): ?array
    {
        if ($depth > self::MAX_SERIALIZED_DEPTH || ! isset($serialized[$offset])) {
            return null;
        }

        $type = $serialized[$offset];

        if ($type === 'N') {
            return $this->consume($serialized, $offset, 'N;')
                ? ['type' => 'N', 'value' => '']
                : null;
        }

        if (in_array($type, ['b', 'i', 'd', 'r', 'R'], true)) {
            if (! $this->consume($serialized, $offset, $type.':')) {
                return null;
            }

            $value = $this->readUntil($serialized, $offset, ';');

            if ($value === null || ! $this->validScalar($type, $value)) {
                return null;
            }

            return ['type' => $type, 'value' => $value];
        }

        if (in_array($type, ['s', 'E'], true)) {
            return $this->readStringValue($serialized, $offset, $type);
        }

        if ($type === 'a') {
            $offset += 2;
            $items = $this->readUnsignedInteger($serialized, $offset, ':');

            if ($items === null
                || $items > self::MAX_SERIALIZED_ITEMS
                || ! $this->consume($serialized, $offset, '{')
            ) {
                return null;
            }

            for ($index = 0; $index < $items * 2; $index++) {
                if ($this->readValue($serialized, $offset, $depth + 1) === null) {
                    return null;
                }
            }

            return $this->consume($serialized, $offset, '}')
                ? ['type' => 'a', 'value' => '']
                : null;
        }

        if ($type === 'O') {
            $header = $this->readObjectHeader($serialized, $offset);

            if ($header === null) {
                return null;
            }

            for ($index = 0; $index < $header['items'] * 2; $index++) {
                if ($this->readValue($serialized, $offset, $depth + 1) === null) {
                    return null;
                }
            }

            return $this->consume($serialized, $offset, '}')
                ? ['type' => 'O', 'value' => '']
                : null;
        }

        return null;
    }

    /** @return array{type: string, value: string}|null */
    private function readStringValue(string $serialized, int &$offset, string $type): ?array
    {
        if (! $this->consume($serialized, $offset, $type.':')) {
            return null;
        }

        $length = $this->readUnsignedInteger($serialized, $offset, ':');

        if ($length === null || ! $this->consume($serialized, $offset, '"')) {
            return null;
        }

        $value = $this->readBytes($serialized, $offset, $length);

        if ($value === null || ! $this->consume($serialized, $offset, '";')) {
            return null;
        }

        return ['type' => $type, 'value' => $value];
    }

    private function readUnsignedInteger(string $serialized, int &$offset, string $terminator): ?int
    {
        $value = $this->readUntil($serialized, $offset, $terminator);

        if ($value === null || $value === '' || ! ctype_digit($value)) {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => self::MAX_SERIALIZED_ITEMS * self::MAX_COMMAND_BYTES],
        ]);

        return is_int($validated) ? $validated : null;
    }

    private function readUntil(string $serialized, int &$offset, string $terminator): ?string
    {
        $position = strpos($serialized, $terminator, $offset);

        if ($position === false) {
            return null;
        }

        $value = substr($serialized, $offset, $position - $offset);
        $offset = $position + strlen($terminator);

        return $value;
    }

    private function readBytes(string $serialized, int &$offset, int $length): ?string
    {
        if ($length < 0 || $offset + $length > strlen($serialized)) {
            return null;
        }

        $value = substr($serialized, $offset, $length);
        $offset += $length;

        return $value;
    }

    private function consume(string $serialized, int &$offset, string $expected): bool
    {
        if (substr($serialized, $offset, strlen($expected)) !== $expected) {
            return false;
        }

        $offset += strlen($expected);

        return true;
    }

    private function validScalar(string $type, string $value): bool
    {
        return match ($type) {
            'b' => in_array($value, ['0', '1'], true),
            'i' => preg_match('/^-?[0-9]+$/', $value) === 1,
            'd' => preg_match('/^(?:-?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?|NAN|INF|-INF)$/', $value) === 1,
            'r', 'R' => preg_match('/^[1-9][0-9]*$/', $value) === 1,
            default => false,
        };
    }
}
