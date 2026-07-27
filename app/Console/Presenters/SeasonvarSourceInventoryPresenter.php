<?php

declare(strict_types=1);

namespace App\Console\Presenters;

use App\DTOs\Seasonvar\SeasonvarSourceInventoryResult;
use App\Enums\SeasonvarPageType;

final class SeasonvarSourceInventoryPresenter
{
    /**
     * @return array{
     *     successful: bool,
     *     rows: list<array{0: string, 1: int}>,
     *     lines: list<string>,
     *     failures: list<string>,
     *     warning: string|null
     * }
     */
    public function present(SeasonvarSourceInventoryResult $result): array
    {
        if (! $result->successful()) {
            return [
                'successful' => false,
                'rows' => [],
                'lines' => [],
                'failures' => array_map(
                    static fn (string $failure): string => 'Ошибка: '.$failure,
                    $result->failureDetails,
                ),
                'warning' => null,
            ];
        }

        return [
            'successful' => true,
            'rows' => collect($result->countsByPageType)
                ->map(function (int $count, string $type): array {
                    $label = SeasonvarPageType::tryFrom($type)?->label()
                        ?? 'неизвестный тип';

                    return ["{$label} ({$type})", $count];
                })
                ->values()
                ->all(),
            'lines' => [
                'Карт сайта: '.$result->sitemapCount,
                'Всего нормализованных URL: '.$result->totalUrlCount,
                'Новых страниц источника: '.$result->storedUrlCount,
                'Неизвестных URL: '.$result->unknownUrlCount,
                'Некорректных URL: '.$result->malformedUrlCount,
                'Заблокированных URL: '.$result->blockedUrlCount,
            ],
            'failures' => [],
            'warning' => $result->discoveredButUnsupportedTypes === []
                ? null
                : 'Нет полного локального parity: '
                    .implode(', ', $result->discoveredButUnsupportedTypes)
                    .'.',
        ];
    }
}
