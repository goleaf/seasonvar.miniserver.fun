<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

final class PersonalTagUpdateRequest extends PersonalTagStoreRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'content_version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function contentVersion(): int
    {
        return (int) $this->validated('content_version');
    }
}
