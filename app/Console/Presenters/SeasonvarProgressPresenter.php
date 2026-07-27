<?php

declare(strict_types=1);

namespace App\Console\Presenters;

use App\Support\HumanFileSizeFormatter;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Carbon;

final readonly class SeasonvarProgressPresenter
{
    private const DATE_TIME_FORMAT = 'd.m.Y H:i';

    public function __construct(private HumanFileSizeFormatter $fileSizes) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, string>  $eventLabels
     * @param  array<string, string>  $contextLabels
     * @param  array<string, string>  $valueLabels
     * @return array{level: 'warning'|'info'|'line', message: string}
     */
    public function present(
        string $event,
        array $context,
        array $eventLabels,
        array $contextLabels,
        array $valueLabels,
    ): array {
        $details = $this->context($context, $contextLabels, $valueLabels);
        $message = '['.now()->format(self::DATE_TIME_FORMAT).'] '
            .($eventLabels[$event] ?? str_replace('-', ' ', $event));

        if ($details !== '') {
            $message .= ': '.$details;
        }

        $level = match (true) {
            str_contains($event, 'failed'),
            str_contains($event, 'blocked'),
            str_contains($event, 'invalid') => 'warning',
            str_contains($event, 'complete'),
            str_contains($event, 'created'),
            str_contains($event, 'stored') => 'info',
            default => 'line',
        };

        return ['level' => $level, 'message' => $message];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, string>  $contextLabels
     * @param  array<string, string>  $valueLabels
     */
    private function context(
        array $context,
        array $contextLabels,
        array $valueLabels,
    ): string {
        $items = [];

        foreach ($context as $key => $value) {
            $items[] = $this->key((string) $key, $contextLabels, $valueLabels)
                .'='
                .$this->value($value, (string) $key, $contextLabels, $valueLabels);
        }

        return implode(' | ', $items);
    }

    /**
     * @param  array<string, string>  $contextLabels
     * @param  array<string, string>  $valueLabels
     */
    private function value(
        mixed $value,
        ?string $key,
        array $contextLabels,
        array $valueLabels,
    ): string {
        if ($value === null) {
            return 'пусто';
        }

        if ($value === true) {
            return 'да';
        }

        if ($value === false) {
            return 'нет';
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(self::DATE_TIME_FORMAT);
        }

        if (is_string($value)) {
            return $valueLabels[$value] ?? $this->string($value);
        }

        if (is_array($value)) {
            return $this->array($value, $contextLabels, $valueLabels);
        }

        if (is_float($value)) {
            return number_format($value, 3, '.', '');
        }

        if ($key === 'file_size_bytes' && is_int($value)) {
            $human = $this->fileSizes->format($value, 'ru');

            return $human === null ? (string) $value : $human.' ('.$value.')';
        }

        return (string) $value;
    }

    private function string(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return Carbon::parse($value)->format('d.m.Y');
        }

        if (preg_match(
            '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/',
            $value,
        ) === 1) {
            return Carbon::parse($value)->format(self::DATE_TIME_FORMAT);
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  array<string, string>  $contextLabels
     * @param  array<string, string>  $valueLabels
     */
    private function array(
        array $value,
        array $contextLabels,
        array $valueLabels,
    ): string {
        if ($value === []) {
            return '[]';
        }

        if (array_is_list($value)) {
            return '['.implode(', ', array_map(
                fn (mixed $item): string => $this->value(
                    $item,
                    null,
                    $contextLabels,
                    $valueLabels,
                ),
                $value,
            )).']';
        }

        $items = [];

        foreach ($value as $key => $item) {
            $items[] = $this->key((string) $key, $contextLabels, $valueLabels)
                .'='
                .$this->value($item, null, $contextLabels, $valueLabels);
        }

        return '{'.implode(', ', $items).'}';
    }

    /**
     * @param  array<string, string>  $contextLabels
     * @param  array<string, string>  $valueLabels
     */
    private function key(
        string $key,
        array $contextLabels,
        array $valueLabels,
    ): string {
        return $contextLabels[$key] ?? $valueLabels[$key] ?? $key;
    }
}
