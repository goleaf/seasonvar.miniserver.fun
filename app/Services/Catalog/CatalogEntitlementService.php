<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogEntitlementDecision;
use App\Enums\ContentAudience;
use App\Enums\PlaybackAvailability;
use App\Enums\PublicationStatus;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class CatalogEntitlementService
{
    public function decide(
        ?User $user,
        CatalogTitle|Season|Episode|LicensedMedia $release,
    ): CatalogEntitlementDecision {
        if ($release->deleted_at !== null || ! $this->hasPublishedState($release)) {
            return CatalogEntitlementDecision::fromStatus(PlaybackAvailability::NotFound);
        }

        if ($release instanceof LicensedMedia && $release->published_at?->isFuture()) {
            return CatalogEntitlementDecision::fromStatus(PlaybackAvailability::NotYetPublished);
        }

        if ($release->available_from?->isFuture()) {
            return CatalogEntitlementDecision::fromStatus(PlaybackAvailability::NotYetPublished);
        }

        if ($release->available_until?->isPast()) {
            return CatalogEntitlementDecision::fromStatus(PlaybackAvailability::Expired);
        }

        if ($release->audience === ContentAudience::Authenticated && $user === null) {
            return CatalogEntitlementDecision::fromStatus(PlaybackAvailability::AuthenticationRequired);
        }

        return CatalogEntitlementDecision::fromStatus(PlaybackAvailability::Ready);
    }

    /**
     * @template TModel of CatalogTitle|Season|Episode|LicensedMedia
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, ?User $user): Builder
    {
        $model = $query->getModel();
        $now = now();

        if ($model instanceof CatalogTitle) {
            $query
                ->where($model->qualifyColumn('is_published'), true)
                ->where($model->qualifyColumn('publication_status'), PublicationStatus::Published->value);
        } elseif ($model instanceof LicensedMedia) {
            $query
                ->where($model->qualifyColumn('status'), 'published')
                ->where(function (Builder $query) use ($model, $now): void {
                    $query
                        ->whereNull($model->qualifyColumn('published_at'))
                        ->orWhere($model->qualifyColumn('published_at'), '<=', $now);
                });
        } else {
            $query->where($model->qualifyColumn('publication_status'), PublicationStatus::Published->value);
        }

        $query
            ->where(function (Builder $query) use ($model, $now): void {
                $query
                    ->whereNull($model->qualifyColumn('available_from'))
                    ->orWhere($model->qualifyColumn('available_from'), '<=', $now);
            })
            ->where(function (Builder $query) use ($model, $now): void {
                $query
                    ->whereNull($model->qualifyColumn('available_until'))
                    ->orWhere($model->qualifyColumn('available_until'), '>=', $now);
            });

        return $user === null
            ? $query->where($model->qualifyColumn('audience'), ContentAudience::Public->value)
            : $query->whereIn($model->qualifyColumn('audience'), [
                ContentAudience::Public->value,
                ContentAudience::Authenticated->value,
            ]);
    }

    /**
     * @template TModel of CatalogTitle|Season|Episode|LicensedMedia
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function constrainQuery(Builder $query, ?User $user): Builder
    {
        return (new self)->constrain($query, $user);
    }

    private function hasPublishedState(CatalogTitle|Season|Episode|LicensedMedia $release): bool
    {
        if ($release instanceof CatalogTitle) {
            return $release->is_published && $release->publication_status === PublicationStatus::Published;
        }

        if ($release instanceof LicensedMedia) {
            return $release->status === 'published';
        }

        return $release->publication_status === PublicationStatus::Published;
    }
}
