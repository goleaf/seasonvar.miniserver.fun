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
            'season.integer' => 'Номер выбранного сезона должен быть числом.',
            'season.min' => 'Номер выбранного сезона должен быть больше нуля.',
            'episode.integer' => 'Номер выбранной серии должен быть числом.',
            'episode.min' => 'Номер выбранной серии должен быть больше нуля.',
            'media.integer' => 'Номер выбранного видео должен быть числом.',
            'media.min' => 'Номер выбранного видео должен быть больше нуля.',
            'variant.string' => 'Вариант просмотра должен быть строкой.',
            'variant.max' => 'Вариант просмотра слишком длинный.',
            'variant.regex' => 'Вариант просмотра имеет неверный формат.',
            'quality.string' => 'Качество должно быть строкой.',
            'quality.max' => 'Качество слишком длинное.',
            'quality.in' => 'Выбрано неподдерживаемое качество.',
            'format.string' => 'Формат должен быть строкой.',
            'format.max' => 'Формат слишком длинный.',
            'format.in' => 'Выбран неподдерживаемый формат.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'season' => 'сезон',
            'episode' => 'серия',
            'media' => 'видео',
            'variant' => 'вариант просмотра',
            'quality' => 'качество',
            'format' => 'формат',
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
