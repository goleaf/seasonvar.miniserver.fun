<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'episode' => ['nullable', 'integer', 'min:1'],
            'media' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'episode.integer' => 'Номер выбранной серии должен быть числом.',
            'episode.min' => 'Номер выбранной серии должен быть больше нуля.',
            'media.integer' => 'Номер выбранного видео должен быть числом.',
            'media.min' => 'Номер выбранного видео должен быть больше нуля.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'episode' => 'серия',
            'media' => 'видео',
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
}
