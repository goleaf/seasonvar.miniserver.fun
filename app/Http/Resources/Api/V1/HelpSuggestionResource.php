<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class HelpSuggestionResource extends JsonResource
{
    /** @return array<string, string> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) data_get($this->resource, 'id'),
            'type' => 'help_article',
            'label' => (string) data_get($this->resource, 'label'),
            'meta' => (string) data_get($this->resource, 'meta'),
            'url' => (string) data_get($this->resource, 'url'),
        ];
    }
}
