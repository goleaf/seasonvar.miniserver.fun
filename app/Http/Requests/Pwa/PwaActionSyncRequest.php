<?php

declare(strict_types=1);

namespace App\Http\Requests\Pwa;

use App\Models\ApiSyncChange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class PwaActionSyncRequest extends FormRequest
{
    private const TYPES = [
        'watchlist.set',
        'rating.set',
    ];

    private const OPERATION_KEYS = [
        'mutation_id',
        'type',
        'title_slug',
        'value',
        'expected_version',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maximum = max(1, min(50, (int) config('pwa.offline.queue_batch_limit', 50)));

        return [
            'operations' => ['required', 'array', 'list', 'min:1', 'max:'.$maximum],
            'operations.*' => ['required', 'array'],
            'operations.*.mutation_id' => ['required', 'uuid', 'distinct:strict'],
            'operations.*.type' => ['required', 'string', Rule::in(self::TYPES)],
            'operations.*.title_slug' => [
                'required',
                'string',
                'max:'.ApiSyncChange::MAX_TITLE_SLUG_LENGTH,
                'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
            ],
            'operations.*.value' => ['present'],
            'operations.*.expected_version' => ['required', 'integer', 'min:0'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['operations']) as $extraKey) {
                $validator->errors()->add(
                    (string) $extraKey,
                    __('pwa.validation.field_unsupported'),
                );
            }

            $operations = $this->input('operations');

            if (! is_array($operations)) {
                return;
            }

            foreach ($operations as $index => $operation) {
                if (! is_array($operation)) {
                    continue;
                }

                foreach (array_diff(array_keys($operation), self::OPERATION_KEYS) as $extraKey) {
                    $validator->errors()->add(
                        "operations.{$index}.{$extraKey}",
                        __('pwa.validation.field_unsupported'),
                    );
                }

                $type = $operation['type'] ?? null;
                $value = $operation['value'] ?? null;

                if ($type === 'watchlist.set' && ! is_bool($value)) {
                    $validator->errors()->add(
                        "operations.{$index}.value",
                        __('pwa.validation.watchlist_boolean'),
                    );
                }

                if ($type === 'rating.set' && $value !== null) {
                    $minimum = max(1, min(255, (int) config('catalog.user_rating.minimum', 1)));
                    $maximum = max($minimum, min(255, (int) config('catalog.user_rating.maximum', 10)));

                    if (! is_int($value) || $value < $minimum || $value > $maximum) {
                        $validator->errors()->add(
                            "operations.{$index}.value",
                            __('pwa.validation.rating_range', [
                                'minimum' => $minimum,
                                'maximum' => $maximum,
                            ]),
                        );
                    }
                }

                if (array_key_exists('expected_version', $operation) && ! is_int($operation['expected_version'])) {
                    $validator->errors()->add(
                        "operations.{$index}.expected_version",
                        __('pwa.validation.version_integer'),
                    );
                }
            }
        }];
    }

    /** @return list<array<string, mixed>> */
    public function operations(): array
    {
        $operations = $this->validated('operations');

        if (! is_array($operations)) {
            return [];
        }

        return collect($operations)
            ->filter(fn (mixed $operation): bool => is_array($operation))
            ->map(fn (array $operation): array => Arr::only($operation, self::OPERATION_KEYS))
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'operations.required' => __('pwa.validation.operations_required'),
            'operations.list' => __('pwa.validation.operations_list'),
            'operations.min' => __('pwa.validation.operations_minimum'),
            'operations.max' => __('pwa.validation.operations_maximum'),
            'operations.*.title_slug.regex' => __('pwa.validation.title_slug_invalid'),
        ];
    }
}
