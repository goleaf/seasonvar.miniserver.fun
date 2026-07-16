<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CatalogPersonalizationConfidence;

final readonly class CatalogPersonalPreferenceProfile
{
    /**
     * @param  list<CatalogPersonalSourceSignal>  $signals
     * @param  array<string, int>  $featureDemotions
     */
    private function __construct(
        public array $signals,
        public CatalogPersonalizationConfidence $confidence,
        public int $totalConfidence,
        public array $featureDemotions,
    ) {}

    /**
     * @param  iterable<CatalogPersonalSourceSignal>  $signals
     * @param  array<string, int>  $featureDemotions
     */
    public static function fromSignals(iterable $signals, array $featureDemotions = []): self
    {
        $byTitle = [];

        foreach ($signals as $signal) {
            if ($signal->confidence < 1) {
                continue;
            }

            $current = $byTitle[$signal->titleId] ?? null;

            if (! $current instanceof CatalogPersonalSourceSignal || $signal->confidence > $current->confidence) {
                $byTitle[$signal->titleId] = $signal;
            }
        }

        $bounded = array_values($byTitle);
        usort($bounded, static fn (CatalogPersonalSourceSignal $left, CatalogPersonalSourceSignal $right): int => ($right->confidence <=> $left->confidence) ?: ($left->titleId <=> $right->titleId));
        $bounded = array_slice(
            $bounded,
            0,
            max(1, min(500, (int) config('recommendations.personalized_v2.history_limit', 120))),
        );
        $total = array_sum(array_map(
            static fn (CatalogPersonalSourceSignal $signal): int => $signal->confidence,
            $bounded,
        ));
        $count = count($bounded);
        $highThreshold = max(240, (int) config('recommendations.personalized_v2.profile_high_threshold', 600));
        $confidence = match (true) {
            $count === 0 => CatalogPersonalizationConfidence::Cold,
            $count >= 3 && $total >= $highThreshold => CatalogPersonalizationConfidence::High,
            $count >= 2 && $total >= 240 => CatalogPersonalizationConfidence::Medium,
            default => CatalogPersonalizationConfidence::Low,
        };

        return new self(
            signals: $bounded,
            confidence: $confidence,
            totalConfidence: $total,
            featureDemotions: self::normalizeDemotions($featureDemotions),
        );
    }

    /** @return list<int> */
    public function sourceTitleIds(): array
    {
        return array_map(
            static fn (CatalogPersonalSourceSignal $signal): int => $signal->titleId,
            $this->signals,
        );
    }

    /**
     * @param  array<string, int>  $demotions
     * @return array<string, int>
     */
    private static function normalizeDemotions(array $demotions): array
    {
        $cap = max(0, (int) config('recommendations.personalized_v2.negative_feature_cap', 120));
        $normalized = [];

        foreach ($demotions as $feature => $value) {
            if ($feature === '' || $value < 1) {
                continue;
            }

            $normalized[$feature] = min($cap, $value);
        }

        ksort($normalized);

        return $normalized;
    }
}
