<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Services\Catalog\CatalogPersonalizationRollout;
use Tests\TestCase;

final class CatalogPersonalizationRolloutTest extends TestCase
{
    public function test_disabled_and_zero_percent_rollouts_never_enable_v2(): void
    {
        $user = $this->user(42);
        $rollout = app(CatalogPersonalizationRollout::class);
        config([
            'recommendations.personalized_v2.enabled' => false,
            'recommendations.personalized_v2.rollout_percent' => 100,
        ]);
        $this->assertFalse($rollout->enabledFor($user));

        config([
            'recommendations.personalized_v2.enabled' => true,
            'recommendations.personalized_v2.rollout_percent' => 0,
        ]);
        $this->assertFalse($rollout->enabledFor($user));
    }

    public function test_full_rollout_always_enables_and_partial_bucket_is_stable(): void
    {
        $rollout = app(CatalogPersonalizationRollout::class);
        config([
            'recommendations.personalized_v2.enabled' => true,
            'recommendations.personalized_v2.rollout_percent' => 100,
            'recommendations.personalized_v2.rollout_seed' => 'stable-v2-test',
        ]);
        $this->assertTrue($rollout->enabledFor($this->user(42)));

        config(['recommendations.personalized_v2.rollout_percent' => 50]);
        $first = $rollout->enabledFor($this->user(42));

        $this->assertSame($first, $rollout->enabledFor($this->user(42)));
        $decisions = collect(range(1, 100))->map(
            fn (int $id): bool => $rollout->enabledFor($this->user($id)),
        );
        $this->assertTrue($decisions->contains(true));
        $this->assertTrue($decisions->contains(false));
    }

    public function test_percent_is_clamped_to_the_zero_to_one_hundred_range(): void
    {
        $rollout = app(CatalogPersonalizationRollout::class);
        config([
            'recommendations.personalized_v2.enabled' => true,
            'recommendations.personalized_v2.rollout_percent' => 999,
        ]);
        $this->assertTrue($rollout->enabledFor($this->user(5)));

        config(['recommendations.personalized_v2.rollout_percent' => -10]);
        $this->assertFalse($rollout->enabledFor($this->user(5)));
    }

    private function user(int $id): User
    {
        return (new User)->forceFill(['id' => $id]);
    }
}
