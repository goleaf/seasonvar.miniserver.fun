<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CatalogRecommendationBuild;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class CatalogRecommendationBuildActivator
{
    public function __construct(
        private readonly CatalogRecommendationCacheInvalidator $cacheInvalidator,
    ) {}

    public function activate(CatalogRecommendationBuild $build): void
    {
        try {
            DB::transaction(function () use ($build): void {
                $selected = CatalogRecommendationBuild::query()
                    ->whereKey($build->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($selected->status !== 'evaluated') {
                    throw new LogicException('Активировать можно только оценённый recommendation build.');
                }

                DB::table('catalog_title_recommendations')->delete();
                DB::table('catalog_title_recommendations')->insertUsing(
                    [
                        'catalog_title_id',
                        'recommended_title_id',
                        'score',
                        'rank',
                        'algorithm_version',
                        'matched_features_count',
                        'metadata_score',
                        'source_score',
                        'quality_score',
                        'reasons',
                        'computed_at',
                        'created_at',
                        'updated_at',
                    ],
                    DB::table('catalog_recommendation_build_rows')
                        ->where('build_id', $selected->id)
                        ->select([
                            'catalog_title_id',
                            'recommended_title_id',
                            'score',
                            'rank',
                        ])
                        ->selectRaw('? as algorithm_version', [$selected->algorithm_version])
                        ->addSelect([
                            'matched_features_count',
                            'metadata_score',
                            'source_score',
                            'quality_score',
                            'reasons',
                            'computed_at',
                            'created_at',
                            'updated_at',
                        ]),
                );

                CatalogRecommendationBuild::query()
                    ->where('status', 'active')
                    ->whereKeyNot($selected->id)
                    ->update([
                        'status' => 'evaluated',
                        'updated_at' => now(),
                    ]);
                $selected->update([
                    'status' => 'active',
                    'completed_at' => $selected->completed_at ?? now(),
                    'activated_at' => now(),
                ]);
            }, attempts: 3);
        } catch (Throwable $exception) {
            CatalogRecommendationBuild::query()
                ->whereKey($build->id)
                ->where('status', '!=', 'active')
                ->update([
                    'status' => 'failed',
                    'failure_message' => Str::limit(
                        $exception::class.': '.$exception->getMessage(),
                        2_000,
                        '',
                    ),
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);

            throw $exception;
        }

        $build->refresh();
        $this->cacheInvalidator->publicSignalsChanged('recommendation-build-activated');
    }
}
