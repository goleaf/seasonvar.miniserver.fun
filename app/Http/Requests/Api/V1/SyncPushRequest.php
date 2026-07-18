<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\ApiSyncChange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SyncPushRequest extends FormRequest
{
    private const TYPES = [
        'watchlist.set',
        'rating.set',
        'progress.set',
        'history.delete',
        'history.clear',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maximumDuration = max(60, min(604800, (int) config('playback.progress.max_duration_seconds', 86400)));

        return [
            'operations' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'operations.*' => ['required', 'array'],
            'operations.*.mutation_id' => ['required', 'uuid', 'distinct:strict'],
            'operations.*.type' => ['required', 'string', Rule::in(self::TYPES)],
            'operations.*.title_slug' => ['sometimes', 'string', 'max:'.ApiSyncChange::MAX_TITLE_SLUG_LENGTH, 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'operations.*.value' => ['sometimes'],
            'operations.*.expected_version' => ['sometimes', 'integer', 'min:0'],
            'operations.*.episode_id' => ['sometimes', 'integer', 'min:1'],
            'operations.*.playback_session' => ['sometimes', 'string', 'min:1', 'max:2048'],
            'operations.*.event_sequence' => ['sometimes', 'integer', 'min:1'],
            'operations.*.position_seconds' => ['sometimes', 'integer', 'min:0', 'max:'.$maximumDuration],
            'operations.*.duration_seconds' => ['sometimes', 'integer', 'min:0', 'max:'.$maximumDuration],
            'operations.*.ended' => ['sometimes', 'boolean:strict'],
            'operations.*.progress_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'operations.required' => __('api.validation.sync.operations_required'),
            'operations.array' => __('api.validation.sync.operations_array'),
            'operations.list' => __('api.validation.sync.operations_list'),
            'operations.min' => __('api.validation.sync.operations_minimum'),
            'operations.max' => __('api.validation.sync.operations_maximum'),
            'operations.*.mutation_id.required' => __('api.validation.sync.mutation_required'),
            'operations.*.mutation_id.uuid' => __('api.validation.sync.mutation_uuid'),
            'operations.*.mutation_id.distinct' => __('api.validation.sync.mutation_distinct'),
            'operations.*.type.required' => __('api.validation.sync.type_required'),
            'operations.*.type.in' => __('api.validation.sync.type_unsupported'),
            'operations.*.title_slug.regex' => __('api.validation.sync.title_slug_invalid'),
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['operations']) as $extraKey) {
                $validator->errors()->add(
                    (string) $extraKey,
                    __('api.validation.sync.top_level_field_unsupported'),
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

                $type = $operation['type'] ?? null;

                if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
                    continue;
                }

                $allowed = $this->allowedKeys($type);

                foreach (array_diff(array_keys($operation), $allowed) as $extraKey) {
                    $validator->errors()->add(
                        "operations.{$index}.{$extraKey}",
                        __('api.validation.sync.field_unsupported'),
                    );
                }

                foreach (array_diff($allowed, array_keys($operation)) as $missingKey) {
                    $validator->errors()->add(
                        "operations.{$index}.{$missingKey}",
                        __('api.validation.sync.field_required'),
                    );
                }

                if ($type === 'watchlist.set' && array_key_exists('value', $operation) && ! is_bool($operation['value'])) {
                    $validator->errors()->add("operations.{$index}.value", __('api.validation.sync.watchlist_boolean'));
                }

                if ($type === 'rating.set' && array_key_exists('value', $operation)) {
                    $value = $operation['value'];
                    $minimum = max(1, min(255, (int) config('catalog.user_rating.minimum', 1)));
                    $maximum = max($minimum, min(255, (int) config('catalog.user_rating.maximum', 10)));

                    if ($value !== null && (! is_int($value) || $value < $minimum || $value > $maximum)) {
                        $validator->errors()->add(
                            "operations.{$index}.value",
                            __('api.validation.rating.range_or_null', [
                                'minimum' => $minimum,
                                'maximum' => $maximum,
                            ]),
                        );
                    }
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
            ->filter(fn (mixed $operation): bool => is_array($operation) && is_string($operation['type'] ?? null))
            ->map(fn (array $operation): array => Arr::only(
                $operation,
                $this->allowedKeys((string) $operation['type']),
            ))
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function allowedKeys(string $type): array
    {
        return match ($type) {
            'watchlist.set', 'rating.set' => ['mutation_id', 'type', 'title_slug', 'value', 'expected_version'],
            'progress.set' => [
                'mutation_id',
                'type',
                'title_slug',
                'episode_id',
                'playback_session',
                'event_sequence',
                'position_seconds',
                'duration_seconds',
                'ended',
            ],
            'history.delete' => ['mutation_id', 'type', 'progress_id'],
            'history.clear' => ['mutation_id', 'type'],
            default => ['mutation_id', 'type'],
        };
    }
}
