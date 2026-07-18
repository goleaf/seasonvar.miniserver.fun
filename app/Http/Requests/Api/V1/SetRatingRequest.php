<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\Services\Catalog\CatalogUserStateService;
use Illuminate\Foundation\Http\FormRequest;

final class SetRatingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, list<mixed>> */
    public function rules(CatalogUserStateService $states): array
    {
        $range = $states->ratingRange();

        return [
            'rating' => [
                'required',
                'integer',
                'between:'.$range['minimum'].','.$range['maximum'],
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'rating.required' => __('api.validation.rating.required'),
            'rating.integer' => __('api.validation.rating.integer'),
            'rating.between' => __('api.validation.rating.range'),
        ];
    }

    public function rating(): int
    {
        return (int) $this->validated('rating');
    }
}
