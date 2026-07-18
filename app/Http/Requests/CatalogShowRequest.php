<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CatalogShowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'season' => ['nullable', 'integer', 'min:1'],
            'episode' => ['nullable', 'integer', 'min:1'],
            'media' => ['nullable', 'integer', 'min:1'],
            'variant' => ['nullable', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'quality' => ['nullable', 'string', Rule::in((array) config('playback.supported_qualities', []))],
            'format' => ['nullable', 'string', Rule::in((array) config('playback.allowed_formats', []))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'season.integer' => __('catalog.player.validation.season_integer'),
            'season.min' => __('catalog.player.validation.season_minimum'),
            'episode.integer' => __('catalog.player.validation.episode_integer'),
            'episode.min' => __('catalog.player.validation.episode_minimum'),
            'media.integer' => __('catalog.player.validation.media_integer'),
            'media.min' => __('catalog.player.validation.media_minimum'),
            'variant.string' => __('catalog.player.validation.variant_string'),
            'variant.max' => __('catalog.player.validation.variant_maximum'),
            'variant.regex' => __('catalog.player.validation.variant_format'),
            'quality.string' => __('catalog.player.validation.quality_string'),
            'quality.max' => __('catalog.player.validation.quality_maximum'),
            'quality.in' => __('catalog.player.validation.quality_supported'),
            'format.string' => __('catalog.player.validation.format_string'),
            'format.max' => __('catalog.player.validation.format_maximum'),
            'format.in' => __('catalog.player.validation.format_supported'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'season' => __('catalog.player.attributes.season'),
            'episode' => __('catalog.player.attributes.episode'),
            'media' => __('catalog.player.attributes.media'),
            'variant' => __('catalog.player.attributes.variant'),
            'quality' => __('catalog.player.attributes.quality'),
            'format' => __('catalog.player.attributes.format'),
        ];
    }

    public function episodeId(): int
    {
        return $this->integer('episode');
    }

    public function mediaId(): int
    {
        return $this->integer('media');
    }

    public function variantKey(): ?string
    {
        return $this->optionalQueryString('variant');
    }

    public function quality(): ?string
    {
        return $this->optionalQueryString('quality');
    }

    public function mediaFormat(): ?string
    {
        return $this->optionalQueryString('format');
    }

    private function optionalQueryString(string $key): ?string
    {
        $value = Str::squish((string) $this->query($key, ''));

        return $value !== '' ? $value : null;
    }
}
