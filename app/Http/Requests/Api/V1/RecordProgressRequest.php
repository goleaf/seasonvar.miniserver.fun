<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class RecordProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $maximumDuration = max(60, min(604800, (int) config('playback.progress.max_duration_seconds', 86400)));

        return [
            'playback_session_token' => ['required', 'string', 'max:2048'],
            'event_sequence' => ['required', 'integer', 'min:1'],
            'position_seconds' => ['required', 'integer', 'min:0', 'max:'.$maximumDuration],
            'reported_duration_seconds' => ['required', 'integer', 'min:0', 'max:'.$maximumDuration],
            'ended' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'playback_session_token.required' => __('api.validation.playback.session_required'),
            'playback_session_token.max' => __('api.validation.playback.session_invalid'),
            'event_sequence.integer' => __('api.validation.playback.event_sequence_integer'),
            'event_sequence.min' => __('api.validation.playback.event_sequence_minimum'),
            'position_seconds.integer' => __('api.validation.playback.position_integer'),
            'position_seconds.min' => __('api.validation.playback.position_minimum'),
            'position_seconds.max' => __('api.validation.playback.position_maximum'),
            'reported_duration_seconds.integer' => __('api.validation.playback.duration_integer'),
            'reported_duration_seconds.min' => __('api.validation.playback.duration_minimum'),
            'reported_duration_seconds.max' => __('api.validation.playback.duration_maximum'),
            'ended.boolean' => __('api.validation.playback.ended_boolean'),
        ];
    }

    public function playbackSessionToken(): string
    {
        return $this->string('playback_session_token')->toString();
    }

    public function eventSequence(): int
    {
        return $this->integer('event_sequence');
    }

    public function positionSeconds(): int
    {
        return $this->integer('position_seconds');
    }

    public function reportedDurationSeconds(): int
    {
        return $this->integer('reported_duration_seconds');
    }

    public function ended(): bool
    {
        return $this->boolean('ended');
    }
}
