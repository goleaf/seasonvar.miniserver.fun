<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\DTOs\PlaybackPreferencesData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class CreatePlaybackSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'episode_id' => ['nullable', 'integer', 'min:1'],
            'media_id' => ['nullable', 'integer', 'min:1'],
            'variant' => ['nullable', 'string', 'max:120'],
            'audio_language' => ['nullable', 'string', 'max:80'],
            'quality' => ['nullable', 'string', Rule::in((array) config('playback.supported_qualities', []))],
            'format' => ['nullable', 'string', Rule::in((array) config('playback.allowed_formats', []))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'episode_id.integer' => 'Идентификатор серии должен быть целым числом.',
            'episode_id.min' => 'Идентификатор серии некорректен.',
            'media_id.integer' => 'Идентификатор видео должен быть целым числом.',
            'media_id.min' => 'Идентификатор видео некорректен.',
            'variant.max' => 'Название варианта слишком длинное.',
            'audio_language.max' => 'Название языка слишком длинное.',
            'quality.in' => 'Выбрано неподдерживаемое качество.',
            'format.in' => 'Выбран неподдерживаемый формат.',
        ];
    }

    public function episodeId(): ?int
    {
        return $this->filled('episode_id') ? $this->integer('episode_id') : null;
    }

    public function mediaId(): ?int
    {
        return $this->filled('media_id') ? $this->integer('media_id') : null;
    }

    public function preferences(): PlaybackPreferencesData
    {
        return new PlaybackPreferencesData(
            variant: $this->nullableString('variant'),
            audioLanguage: $this->nullableString('audio_language'),
            quality: $this->nullableString('quality'),
            format: $this->nullableString('format'),
        );
    }

    private function nullableString(string $key): ?string
    {
        if (! $this->filled($key)) {
            return null;
        }

        return Str::lower(Str::squish($this->string($key)->toString()));
    }
}
