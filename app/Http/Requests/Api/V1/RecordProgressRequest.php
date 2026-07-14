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
            'playback_session_token.required' => 'Отсутствует сессия просмотра.',
            'playback_session_token.max' => 'Сессия просмотра некорректна.',
            'event_sequence.integer' => 'Номер события должен быть целым числом.',
            'event_sequence.min' => 'Номер события должен быть не меньше 1.',
            'position_seconds.integer' => 'Позиция должна быть целым числом.',
            'position_seconds.min' => 'Позиция не может быть отрицательной.',
            'position_seconds.max' => 'Позиция превышает допустимую длительность.',
            'reported_duration_seconds.integer' => 'Длительность должна быть целым числом.',
            'reported_duration_seconds.min' => 'Длительность не может быть отрицательной.',
            'reported_duration_seconds.max' => 'Длительность превышает допустимое значение.',
            'ended.boolean' => 'Признак завершения должен быть логическим значением.',
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
