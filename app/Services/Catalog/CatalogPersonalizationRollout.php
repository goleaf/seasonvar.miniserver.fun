<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\User;

final class CatalogPersonalizationRollout
{
    public function enabledFor(User $user): bool
    {
        if (! (bool) config('recommendations.personalized_v2.enabled', false)) {
            return false;
        }

        $percent = max(0, min(100, (int) config(
            'recommendations.personalized_v2.rollout_percent',
            0,
        )));

        if ($percent === 0) {
            return false;
        }

        if ($percent === 100) {
            return true;
        }

        $seed = (string) config('recommendations.personalized_v2.rollout_seed', 'personalized-v2');
        $bucket = (int) (hexdec(substr(hash('sha256', $seed.'|'.$user->getKey()), 0, 8)) % 100);

        return $bucket < $percent;
    }
}
