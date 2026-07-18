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
            'episode_id.integer' => __('api.validation.playback.episode_id_integer'),
            'episode_id.min' => __('api.validation.playback.episode_id_invalid'),
            'media_id.integer' => __('api.validation.playback.media_id_integer'),
            'media_id.min' => __('api.validation.playback.media_id_invalid'),
            'variant.max' => __('api.validation.playback.variant_maximum'),
            'audio_language.max' => __('api.validation.playback.audio_language_maximum'),
            'quality.in' => __('api.validation.playback.quality_unsupported'),
            'format.in' => __('api.validation.playback.format_unsupported'),
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
