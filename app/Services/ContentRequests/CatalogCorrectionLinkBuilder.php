<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use App\Enums\ContentCorrectionField;
use App\Models\CatalogTitle;

final class CatalogCorrectionLinkBuilder
{
    public function for(
        CatalogTitle $title,
        ContentCorrectionField $field,
        ?int $targetId = null,
        ?string $locale = null,
    ): string {
        $parameters = [
            'type' => $field->requestType()->value,
            'catalog_title_id' => $title->id,
            'field' => $field->value,
        ];

        if ($targetId !== null) {
            $parameters['target'] = $targetId;
        }

        if ($locale !== null) {
            return route('localized.requests.create', [
                'locale' => $locale,
                ...$parameters,
            ]);
        }

        return route('requests.create', $parameters);
    }
}
