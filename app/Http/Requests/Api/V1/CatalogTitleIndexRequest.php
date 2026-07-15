<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\CatalogSort;
use App\Http\Requests\CatalogTitlesRequest;

final class CatalogTitleIndexRequest extends CatalogTitlesRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();

        unset($rules['title'], $rules['type'], $rules['taxonomy']);

        $rules['page'] = ['sometimes', 'integer', 'min:1'];
        $rules['per_page'] = ['sometimes', 'integer', 'min:1', 'max:50'];

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $requestedSort = $this->query('sort');

        parent::prepareForValidation();

        if (is_array($requestedSort)) {
            $this->merge(['sort' => $requestedSort]);

            return;
        }

        if (is_scalar($requestedSort)) {
            $requestedSort = trim((string) $requestedSort);

            if ($requestedSort !== '' && CatalogSort::tryFrom($requestedSort) === null) {
                $this->merge(['sort' => $requestedSort]);
            }
        }
    }

    public function perPage(): int
    {
        return $this->integer('per_page', (int) config('mobile-api.default_per_page', 20));
    }
}
