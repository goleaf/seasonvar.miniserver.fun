<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

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
            'variant' => ['nullable', 'string', 'max:160'],
            'quality' => ['nullable', 'string', 'max:32'],
            'format' => ['nullable', 'string', 'max:32'],
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
            'quality.string' => 'Качество должно быть строкой.',
            'quality.max' => 'Качество слишком длинное.',
            'format.string' => 'Формат должен быть строкой.',
            'format.max' => 'Формат слишком длинный.',
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
